<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\CustomerFormData;
use App\Data\CustomerRowData;
use App\Enums\CustomerSegment;
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
            'formats' => ExportResponse::available(),
            'counts' => $this->counts(),
            'can' => [
                'create' => $viewer->can('create', Customer::class),
                'update' => $viewer->can('update', new Customer),
                'delete' => $viewer->can('delete', new Customer),
                'export' => $viewer->can('export', Customer::class),
                'import' => $viewer->can('import', Customer::class),
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse|HttpResponse|RedirectResponse
    {
        $this->authorize('export', Customer::class);

        $filters = $this->filters($request);
        $query = $this->filtered($filters)->orderBy('id');

        $format = ExportResponse::format($request->query('format'));

        return ExportResponse::make(
            new CustomerExport($query, $format),
            'customers-'.CarbonImmutable::today()->toDateString(),
            $format,
            'Customers',
            $this->exportSubtitle($filters),
        );
    }

    public function import(Request $request, LegacyExtract $extract): RedirectResponse
    {
        $this->authorize('import', Customer::class);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        $import = new CustomerImport($extract, $request->user()->id);

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
            'segments' => CustomerSegment::options(),
            'default_country' => 'Kenya',
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('admin/customers/Form', [
            'customer' => CustomerFormData::fromModel($customer),
            'types' => CustomerType::options(),
            'segments' => CustomerSegment::options(),
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
     * One definition shared by the screen and the download - keep it that way.
     *
     * @param  array{search: string, type: string}  $filters
     * @return Builder<Customer>
     */
    private function filtered(array $filters): Builder
    {
        return Customer::query()
            ->withCount('visits')
            ->withMax('visits', 'visited_at')
            ->when($filters['search'] !== '', fn (Builder $query) => $query->search($filters['search']))
            ->when(
                $filters['type'] !== '',
                fn (Builder $query) => $query->ofType(CustomerType::from($filters['type'])),
            );
    }

    /**
     * @param  array{search: string, type: string}  $filters
     */
    private function exportSubtitle(array $filters): string
    {
        $parts = [ExportWindow::label(null, null)];

        if ($filters['type'] !== '') {
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
     * Unfiltered on purpose: a count that moved with the search would leave the tab you
     * are on reading its own result back.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counted = Customer::query()
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        $counts = ['all' => (int) $counted->sum()];

        foreach (CustomerType::cases() as $type) {
            $counts[$type->value] = (int) $counted->get($type->value, 0);
        }

        return $counts;
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
