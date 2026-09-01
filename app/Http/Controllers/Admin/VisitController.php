<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\CustomerOptionData;
use App\Data\DashboardRangeData;
use App\Data\DashboardStatData;
use App\Data\ProductOptionData;
use App\Data\VisitFormData;
use App\Data\VisitRowData;
use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\InterestLevel;
use App\Enums\Permission;
use App\Enums\VisitPurpose;
use App\Enums\VisitReport;
use App\Exports\VisitExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VisitRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Visit;
use App\Support\Http\DateWindow;
use App\Support\Http\ExportResponse;
use App\Support\Http\PageSize;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

# Every query here runs through `visible()` — that is the whole of the own-vs-floor split.
class VisitController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Visit::class);

        $viewer = $request->user();
        $filters = $this->filters($request);

        $visits = $this->filtered($viewer, $filters)
            ->with(VisitRowData::RELATIONS)
            # The id breaks ties; visits land on the same round hour and the pager repeats rows.
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(VisitRowData::fromModel(...));

        return Inertia::render('admin/visits/Index', [
            'visits' => $visits,
            'filters' => $filters,
            'date_label' => $this->windowLabel($filters),
            'presets' => DashboardRangeData::options(),
            'window_days' => $this->precedingWindow($filters)['days'] ?? null,
            'purposes' => VisitPurpose::options(),
            'page_sizes' => PageSize::OPTIONS,
            'formats' => ExportResponse::available(),
            'stats' => $this->stats($viewer, $filters),
            'scoped_to_own' => ! $viewer->can(Permission::VisitsViewAny->value),
            'can' => [
                'create' => $viewer->can('create', Visit::class),
                'update' => $viewer->can(Permission::VisitsUpdate->value),
                'delete' => $viewer->can(Permission::VisitsDelete->value),
                'export' => $viewer->can('export', Visit::class),
            ],
        ]);
    }

    /**
     * Narrowed by the date window only — deliberately NOT by search or purpose, which ask
     * "which of these rows did I mean" rather than "which stretch of the log am I reading".
     *
     * @param  array{search: string, purpose: string, range: string, from: string, to: string}  $filters
     * @return array<int, DashboardStatData>
     */
    private function stats(User $viewer, array $filters): array
    {
        $now = $this->totals($viewer, $filters['from'], $filters['to']);
        $preceding = $this->precedingWindow($filters);

        $before = $preceding === null
            ? ['visits' => 0, 'customers' => 0, 'follow_ups' => 0]
            : $this->totals($viewer, $preceding['from'], $preceding['to']);

        return [
            DashboardStatData::compare(
                'visits',
                $viewer->can(Permission::VisitsViewAny->value) ? 'Total visits' : 'Visits you logged',
                $now['visits'],
                $before['visits'],
            ),
            DashboardStatData::compare('customers', 'Unique customers', $now['customers'], $before['customers']),
            DashboardStatData::compare('follow_ups', 'Follow-ups promised', $now['follow_ups'], $before['follow_ups']),
        ];
    }

    /**
     * `toBase()`: off a hydrated `Visit` an alias matching a relation or accessor resolves
     * to that instead of to the column.
     *
     * @return array{visits: int, customers: int, follow_ups: int}
     */
    private function totals(User $viewer, string $from, string $to): array
    {
        $counted = $this->betweenDates($this->visible($viewer), $from, $to)
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('COUNT(DISTINCT visits.customer_id) AS customers')
            ->selectRaw('COUNT(visits.expected_follow_up_on) AS follow_ups')
            ->toBase()
            ->first();

        return [
            'visits' => (int) ($counted?->visits ?? 0),
            'customers' => (int) ($counted?->customers ?? 0),
            'follow_ups' => (int) ($counted?->follow_ups ?? 0),
        ];
    }

    /**
     * @param  array{search: string, purpose: string, range: string, from: string, to: string}  $filters
     * @return array{days: int, from: string, to: string}|null
     */
    private function precedingWindow(array $filters): ?array
    {
        return DateWindow::preceding($filters['from'], $filters['to']);
    }

    # Built off `filtered()` so exporting cannot widen past the viewer's visibility split.
    public function export(Request $request): BinaryFileResponse|HttpResponse|RedirectResponse
    {
        $this->authorize('export', Visit::class);

        $viewer = $request->user();
        $filters = $this->filters($request);

        $query = $this->filtered($viewer, $filters)
            ->with(VisitRowData::RELATIONS)
            ->orderByDesc('visited_at')
            ->orderByDesc('id');

        # Chooses columns only; the rows were already filtered and authorised above.
        $report = VisitReport::forViewer($viewer);

        return ExportResponse::make(
            new VisitExport($query, $report),
            $report->basename().'-'.CarbonImmutable::today()->toDateString(),
            ExportResponse::format($request->query('format')),
            $report->title(),
            $this->exportSubtitle($viewer, $filters),
        );
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Visit::class);

        return Inertia::render('admin/visits/Form', [
            'visit' => null,
            ...$this->formOptions($request->user()),
        ]);
    }

    public function edit(Request $request, Visit $visit): Response
    {
        $this->authorize('update', $visit);

        $visit->load('products', 'customer');

        return Inertia::render('admin/visits/Form', [
            'visit' => VisitFormData::fromModel($visit),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(VisitRequest $request): RedirectResponse
    {
        $visit = DB::transaction(function () use ($request) {
            $customer = $request->resolveCustomer();

            $visit = new Visit($request->visitAttributes());
            $visit->customer_id = $customer->id;
            $visit->visited_at = $request->visitedAt();
            $visit->created_by = $request->user()->id;
            $visit->save();

            $visit->products()->sync($request->productSync());

            return $visit;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The visit by :name has been added.', [
                'name' => $visit->customer->displayName(),
            ]),
        ]);

        return to_route('admin.visits.index');
    }

    public function update(VisitRequest $request, Visit $visit): RedirectResponse
    {
        DB::transaction(function () use ($request, $visit) {
            $visit->fill($request->visitAttributes());
            $visit->customer_id = $request->resolveCustomer()->id;
            $visit->visited_at = $request->visitedAt();
            $visit->save();

            $visit->products()->sync($request->productSync());
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The visit by :name has been saved.', [
                'name' => $visit->customer->displayName(),
            ]),
        ]);

        return to_route('admin.visits.index');
    }

    public function destroy(Visit $visit): RedirectResponse
    {
        $this->authorize('delete', $visit);

        $name = $visit->customer->displayName();

        $visit->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The visit by :name has been removed.', ['name' => $name]),
        ]);

        return back();
    }

    /**
     * `visits.view.any` is the whole floor; anything less is what they logged themselves.
     * The list-shaped counterpart of what the policy says for one record.
     *
     * @return Builder<Visit>
     */
    private function visible(User $viewer): Builder
    {
        return Visit::query()->unless(
            $viewer->can(Permission::VisitsViewAny->value),
            fn (Builder $query) => $query->loggedBy($viewer),
        );
    }

    /**
     * One definition shared by the screen and the download — keep it that way.
     *
     * @param  array{search: string, purpose: string, range: string, from: string, to: string}  $filters
     * @return Builder<Visit>
     */
    private function filtered(User $viewer, array $filters): Builder
    {
        $query = $this->visible($viewer)
            ->when(
                $filters['search'] !== '',
                fn (Builder $query) => $query->search($filters['search']),
            )
            ->when(
                $filters['purpose'] !== '',
                fn (Builder $query) => $query->forPurpose(VisitPurpose::from($filters['purpose'])),
            );

        return $this->betweenDates($query, $filters['from'], $filters['to']);
    }

    /**
     * The ends are dates, `visited_at` is a datetime. The far end is `< the following
     * midnight`, never `<= 23:59:59` — the second-precision reading drops a visit stamped
     * in the last second of the day, and more on a host storing fractional seconds.
     *
     * @param  Builder<Visit>  $query
     * @return Builder<Visit>
     */
    private function betweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->when(
                $from !== '',
                fn (Builder $query) => $query->where(
                    'visits.visited_at',
                    '>=',
                    CarbonImmutable::parse($from)->startOfDay(),
                ),
            )
            ->when(
                $to !== '',
                fn (Builder $query) => $query->where(
                    'visits.visited_at',
                    '<',
                    CarbonImmutable::parse($to)->addDay()->startOfDay(),
                ),
            );
    }

    /**
     * @param  array{search: string, purpose: string, range: string, from: string, to: string}  $filters
     */
    private function exportSubtitle(User $viewer, array $filters): string
    {
        $parts = [
            $viewer->can(Permission::VisitsViewAny->value)
                ? 'Every visit'
                : 'Visits logged by '.$viewer->name,
            $this->windowLabel($filters),
        ];

        if ($filters['purpose'] !== '') {
            $parts[] = VisitPurpose::from($filters['purpose'])->label();
        }

        if ($filters['search'] !== '') {
            $parts[] = 'matching "'.$filters['search'].'"';
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(User $viewer): array
    {
        return [
            'customers' => Customer::query()
                ->orderBy('name')
                ->orderBy('company_name')
                ->get()
                ->map(CustomerOptionData::fromModel(...))
                ->values(),
            'products' => Product::query()
                ->orderBy('name')
                # `image_path` must stay in the list: `imageUrl()` builds the thumbnail
                # from it, and without it every tile comes back blank.
                ->get(['id', 'name', 'sku', 'model_number', 'image_path'])
                ->map(ProductOptionData::fromModel(...))
                ->values(),
            'types' => CustomerType::options(),
            'purposes' => VisitPurpose::options(),
            'sources' => CustomerSource::options(),
            'interest_levels' => InterestLevel::options(),
            # Without `customers.update` the details render read-only, rather than offering an
            # edit that is silently dropped on the way in.
            'can_update_customer' => $viewer->can('update', new Customer),
        ];
    }

    /**
     * @return array{search: string, purpose: string, range: string, from: string, to: string}
     */
    private function filters(Request $request): array
    {
        $purpose = $request->string('purpose')->toString();
        [$range, $from, $to] = $this->window($request);

        return [
            'search' => $request->string('search')->trim()->toString(),
            'purpose' => in_array($purpose, VisitPurpose::values(), true) ? $purpose : '',
            # Carried alongside the resolved dates purely so the picker can keep showing
            # "This month" — everything downstream reads `from`/`to`.
            'range' => $range,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function window(Request $request): array
    {
        return DateWindow::fromRequest($request);
    }

    /**
     * @param  array{search: string, purpose: string, range: string, from: string, to: string}  $filters
     */
    private function windowLabel(array $filters): string
    {
        return DateWindow::label($filters['from'], $filters['to']);
    }
}
