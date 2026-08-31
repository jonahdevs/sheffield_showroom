<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\CustomerOptionData;
use App\Data\DashboardStatData;
use App\Data\ProductOptionData;
use App\Data\VisitFormData;
use App\Data\VisitRowData;
use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\InterestLevel;
use App\Enums\Permission;
use App\Enums\VisitPurpose;
use App\Exports\VisitExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VisitRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Visit;
use App\Support\Http\ExportResponse;
use App\Support\Http\ExportWindow;
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

/**
 * Calls at the showroom: who came, why, and what they were shown.
 *
 * Every query here runs through `visible()`, which is what keeps a
 * salesperson's list to their own visits and a manager's to the whole floor.
 */
class VisitController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Visit::class);

        $viewer = $request->user();
        $filters = $this->filters($request);

        $visits = $this->filtered($viewer, $filters)
            /* `products` by name only, and eager: the row names what they
               were shown, and eager-loading it is one extra read for the whole
               page rather than one per row. */
            ->with(VisitRowData::RELATIONS)
            /* Newest visit first, and the id to break a tie - several visits
               land on the same round hour, and without it the pager repeats
               rows across pages. */
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(VisitRowData::fromModel(...));

        return Inertia::render('admin/visits/Index', [
            'visits' => $visits,
            'filters' => $filters,
            'purposes' => VisitPurpose::options(),
            'page_sizes' => PageSize::OPTIONS,
            /* Only the formats this host can actually produce - see
               `ExportResponse::available()`. */
            'formats' => ExportResponse::available(),
            'stats' => $this->stats($viewer),
            /* A salesperson sees their own visits; saying so stops the list
               reading as though the showroom had a quiet week. */
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
     * The four figures above the list, each against the window before it.
     *
     * Deliberately NOT narrowed by the search or the purpose filter. These
     * answer "how busy has the floor been", which is a standing question about
     * the log rather than about whatever is currently typed in the box - and
     * the pager already says how many rows a filter matched, so repeating that
     * here would be a second answer to a question nobody asked twice.
     *
     * Counted in one pass with conditional aggregates rather than eight round
     * trips: `visited_at` is indexed and every window is a bound on that one
     * column, so the database can answer them together.
     *
     * @return array<int, DashboardStatData>
     */
    private function stats(User $viewer): array
    {
        $now = CarbonImmutable::now();
        $day = $now->startOfDay();
        $week = $now->startOfWeek();
        $month = $now->startOfMonth();

        /* `CASE WHEN` rather than `SUM(visited_at >= ?)`: the shorthand leans
           on booleans summing as integers, which is true of MySQL and SQLite
           but is not something to bet a figure on. */
        $since = 'COUNT(CASE WHEN visited_at >= ? THEN 1 END)';
        $between = 'COUNT(CASE WHEN visited_at >= ? AND visited_at < ? THEN 1 END)';

        $row = $this->visible($viewer)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("{$since} AS today", [$day])
            ->selectRaw("{$since} AS this_week", [$week])
            ->selectRaw("{$since} AS this_month", [$month])
            ->selectRaw("{$between} AS yesterday", [$day->subDay(), $day])
            ->selectRaw("{$between} AS last_week", [$week->subWeek(), $week])
            ->selectRaw("{$between} AS last_month", [$month->subMonth(), $month])
            ->first();

        $count = fn (string $key): int => (int) ($row?->{$key} ?? 0);

        return [
            /* Compared against nothing, because a running total has nothing
               before it - it is every visit there has ever been. The tile
               renders that as "Nothing before it to compare" rather than
               inventing a window for it. */
            DashboardStatData::compare(
                'total',
                /* Claiming a floor-wide total while showing a personal one
                   would be a quiet lie, so the label says which it is. */
                $viewer->can(Permission::VisitsViewAny->value) ? 'Total visits' : 'Visits you logged',
                $count('total'),
                previous: 0,
            ),
            DashboardStatData::compare('today', 'Today', $count('today'), $count('yesterday')),
            DashboardStatData::compare('week', 'This week', $count('this_week'), $count('last_week')),
            DashboardStatData::compare('month', 'This month', $count('this_month'), $count('last_month')),
        ];
    }

    /**
     * The log the viewer is looking at, as a file.
     *
     * Through `filtered()` like the list is, so the download carries the same
     * split: a manager gets the floor, a salesperson gets what they logged.
     * Exporting is not a way around the visibility rule, and building the file
     * off the same query is what makes that true by construction rather than
     * by somebody remembering to add the scope here too.
     */
    public function export(Request $request): BinaryFileResponse|HttpResponse|RedirectResponse
    {
        $this->authorize('export', Visit::class);

        $viewer = $request->user();
        $filters = $this->filters($request);

        $query = $this->filtered($viewer, $filters)
            ->with(VisitRowData::RELATIONS)
            ->orderByDesc('visited_at')
            ->orderByDesc('id');

        return ExportResponse::make(
            new VisitExport($query),
            'visits-'.CarbonImmutable::today()->toDateString(),
            ExportResponse::format($request->query('format')),
            'Visits',
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
            /* The customer first: they may be new, and the visit cannot be
               filed until there is somebody to file it against. */
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

            /* `sync` rather than `attach`: what was shown is the list the form
               came back with, not that list added to the old one - and the
               interest against each comes back with it. */
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

    /**
     * Soft deleted. A visit is what the floor is measured by, and a month that
     * quietly loses a row is a month nobody can reconcile.
     */
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
     * The visits this user is allowed to see at all.
     *
     * `visits.view.any` is the whole floor; anything less is what they logged
     * themselves. The policy says the same thing for one record - this is the
     * query that says it for a list.
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
     * The visits this user may see, under a set of filters.
     *
     * One definition, shared by the screen and the download, so an export
     * cannot quietly widen past either the filters the viewer set or the
     * visibility split they sit behind.
     *
     * @param  array{search: string, purpose: string}  $filters
     * @return Builder<Visit>
     */
    private function filtered(User $viewer, array $filters): Builder
    {
        return $this->visible($viewer)
            ->when(
                $filters['search'] !== '',
                fn (Builder $query) => $query->search($filters['search']),
            )
            ->when(
                $filters['purpose'] !== '',
                fn (Builder $query) => $query->forPurpose(VisitPurpose::from($filters['purpose'])),
            );
    }

    /**
     * The line under the title on a printed export: which slice of the log
     * this is.
     *
     * Whose visits it holds is named first. A salesperson's printed log is not
     * a short month on the floor, and a sheet that does not say so is one
     * somebody will read as the whole showroom's.
     *
     * @param  array{search: string, purpose: string}  $filters
     */
    private function exportSubtitle(User $viewer, array $filters): string
    {
        $parts = [
            $viewer->can(Permission::VisitsViewAny->value)
                ? 'Every visit'
                : 'Visits logged by '.$viewer->name,
            /* The visits screen has no date filter, so the window is the whole
               log - said out loud, because a printed sheet has nothing else on
               it to say how far back it reaches. */
            ExportWindow::label(null, null),
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
     * What the name box suggests and the product box chooses between, plus the
     * fixed lists.
     *
     * The lists are sent whole rather than searched over the wire: the boxes
     * narrow as you type, and a round trip per keystroke is a worse trade at
     * this size than a few hundred rows in the payload.
     *
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
                /* `image_path` among them, or `imageUrl()` has nothing to
                   build the thumbnail from and every tile comes back blank. */
                ->get(['id', 'name', 'sku', 'model_number', 'image_path'])
                ->map(ProductOptionData::fromModel(...))
                ->values(),
            'types' => CustomerType::options(),
            'purposes' => VisitPurpose::options(),
            'sources' => CustomerSource::options(),
            'interest_levels' => InterestLevel::options(),
            /* The form corrects a customer it is attached to. Whoever may not
               edit customers gets the details read-only instead of an edit
               that is silently dropped on the way in. */
            'can_update_customer' => $viewer->can('update', new Customer),
        ];
    }

    /**
     * @return array{search: string, purpose: string}
     */
    private function filters(Request $request): array
    {
        $purpose = $request->string('purpose')->toString();

        return [
            'search' => $request->string('search')->trim()->toString(),
            'purpose' => in_array($purpose, VisitPurpose::values(), true) ? $purpose : '',
        ];
    }
}
