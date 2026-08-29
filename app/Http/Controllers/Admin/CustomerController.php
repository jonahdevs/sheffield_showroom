<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\CustomerFormData;
use App\Data\CustomerRowData;
use App\Enums\CustomerType;
use App\Exports\CustomerExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerRequest;
use App\Imports\CustomerImport;
use App\Models\Customer;
use App\Services\Customers\LegacyExtract;
use App\Support\Http\ExportResponse;
use App\Support\Http\ExportWindow;
use App\Support\Http\PageSize;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The people and organisations who visit the showroom.
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $viewer = $request->user();
        $filters = $this->filters($request);

        $customers = $this->filtered($filters)
            ->latest('id')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(CustomerRowData::fromModel(...));

        return Inertia::render('admin/customers/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'types' => CustomerType::options(),
            'page_sizes' => PageSize::OPTIONS,
            /* Only the formats this host can actually produce - see
               `ExportResponse::available()`. */
            'formats' => ExportResponse::available(),
            'counts' => [
                'all' => Customer::query()->count(),
                'individual' => Customer::query()->ofType(CustomerType::Individual)->count(),
                'company' => Customer::query()->ofType(CustomerType::Company)->count(),
            ],
            'can' => [
                'create' => $viewer->can('create', Customer::class),
                'update' => $viewer->can('update', new Customer),
                'delete' => $viewer->can('delete', new Customer),
                'export' => $viewer->can('export', Customer::class),
                'import' => $viewer->can('import', Customer::class),
            ],
        ]);
    }

    /**
     * The list the viewer is looking at, as a file.
     *
     * Built from the same query the list is, filters and all, so what
     * downloads is what was on the screen rather than the whole table. A
     * salesperson who searched for one company and exported should get that
     * company.
     */
    public function export(Request $request): BinaryFileResponse|HttpResponse|RedirectResponse
    {
        $this->authorize('export', Customer::class);

        $filters = $this->filters($request);
        $query = $this->filtered($filters)->orderBy('id');

        return ExportResponse::make(
            new CustomerExport($query),
            'customers-'.CarbonImmutable::today()->toDateString(),
            ExportResponse::format($request->query('format')),
            'Customers',
            /* Only the paper format prints this, and only paper needs it: a
               spreadsheet carries its filename and a workbook tab, where a
               printed sheet has nothing on it to say which slice of the list
               it is. The customers screen has no date filter, so the window
               reads as the whole history and the narrowing is whatever the
               toolbar was set to. */
            $this->exportSubtitle($filters),
        );
    }

    /**
     * The same file, going the other way.
     *
     * One step rather than the upload-then-confirm the file size here does not
     * warrant: the counts come back as a toast, and a row the rules refused is
     * reported in it rather than stopping the rest of the file landing.
     */
    public function import(Request $request): RedirectResponse
    {
        $this->authorize('import', Customer::class);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        $import = new CustomerImport(app(LegacyExtract::class), $request->user()->id);

        /* All or nothing on anything the import did not expect. A row it did
           expect to refuse is skipped without throwing, so the transaction is
           there for the failures nobody wrote a rule for - a column that
           breaks a constraint halfway through a four hundred row file. */
        DB::transaction(fn () => Excel::import($import, $file));

        $summary = $import->summary();

        Inertia::flash('toast', [
            'type' => $summary['skipped'] > 0 ? 'warning' : 'success',
            'message' => __(':created added, :updated updated, :skipped skipped.', $summary),
        ]);

        return to_route('admin.customers.index');
    }

    public function create(): Response
    {
        $this->authorize('create', Customer::class);

        return Inertia::render('admin/customers/Form', [
            'customer' => null,
            'types' => CustomerType::options(),
            'default_country' => 'Kenya',
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('admin/customers/Form', [
            'customer' => CustomerFormData::fromModel($customer),
            'types' => CustomerType::options(),
            'default_country' => 'Kenya',
        ]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = new Customer($request->validated());
        $customer->created_by = $request->user()->id;
        $customer->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been added.', ['name' => $customer->displayName()]),
        ]);

        return to_route('admin.customers.index');
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been saved.', ['name' => $customer->displayName()]),
        ]);

        return to_route('admin.customers.index');
    }

    /**
     * Soft deleted. A customer is attached to the visits they made, and a hard
     * delete would take that history with them.
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $name = $customer->displayName();

        $customer->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been removed.', ['name' => $name]),
        ]);

        return back();
    }

    /**
     * The customer list under a set of filters.
     *
     * One definition, shared by the screen and the download, so an export
     * cannot quietly widen past what the viewer had narrowed to.
     *
     * @param  array{search: string, type: string}  $filters
     * @return Builder<Customer>
     */
    private function filtered(array $filters): Builder
    {
        return Customer::query()
            /* How many calls they have made and when the last one was. Counted
               and maxed in the one query rather than loaded: the row shows two
               numbers, not the visits behind them. */
            ->withCount('visits')
            ->withMax('visits', 'visited_at')
            ->when($filters['search'] !== '', fn (Builder $query) => $query->search($filters['search']))
            ->when(
                $filters['type'] !== '',
                fn (Builder $query) => $query->ofType(CustomerType::from($filters['type'])),
            );
    }

    /**
     * The line under the title on a printed export: which slice of the list
     * this is.
     *
     * @param  array{search: string, type: string}  $filters
     */
    private function exportSubtitle(array $filters): string
    {
        $parts = [ExportWindow::label(null, null)];

        if ($filters['type'] !== '') {
            /* Spelled out rather than pluralised off the label, which would
               print "Companys" on the paper. */
            $parts[] = match (CustomerType::from($filters['type'])) {
                CustomerType::Individual => 'Individuals only',
                CustomerType::Company => 'Companies only',
            };
        }

        if ($filters['search'] !== '') {
            $parts[] = 'matching "'.$filters['search'].'"';
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{search: string, type: string}
     */
    private function filters(Request $request): array
    {
        $type = $request->string('type')->toString();

        return [
            'search' => $request->string('search')->trim()->toString(),
            'type' => in_array($type, CustomerType::values(), true) ? $type : '',
        ];
    }
}
