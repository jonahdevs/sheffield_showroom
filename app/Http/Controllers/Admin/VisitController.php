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
            /* The window in words, resolved here rather than on the page. The
               server is what settles a pair the wrong way round or a date that
               would not parse, so the calendar has to read its label off the
               answer instead of off the click - and it is the same sentence the
               printed export carries, so a sheet and the screen it came from
               cannot describe their window differently. */
            'date_label' => $this->windowLabel($filters),
            /* The named windows the picker offers as one click each. Borrowed
               from the dashboard's list rather than written again here, so
               "this month" means the same days on both screens. */
            'presets' => DashboardRangeData::options(),
            /* How long the chosen window is, which is the only thing the tiles'
               "vs previous N days" caption needs and is null where the window
               has no length to speak of - see `precedingWindow()`. The sentence
               itself is built on the page, because how a figure is captioned is
               presentation and a controller has no business writing it. */
            'window_days' => $this->precedingWindow($filters)['days'] ?? null,
            'purposes' => VisitPurpose::options(),
            'page_sizes' => PageSize::OPTIONS,
            /* Only the formats this host can actually produce - see
               `ExportResponse::available()`. */
            'formats' => ExportResponse::available(),
            'stats' => $this->stats($viewer, $filters),
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
     * The three figures above the list, each for the window the page is being
     * read under and each against the equally long window immediately before
     * it.
     *
     * This row used to hold four fixed windows - a running total, today, this
     * week, this month - counted in one pass with conditional aggregates. That
     * set could not survive the date picker moving to the top of the page: a
     * reader who asks for February and is answered with "today" and "this
     * month" is being shown a row that has nothing to do with the list under
     * it, and there is no honest way to compose a fixed window with an
     * arbitrary one. The named windows themselves are not lost - the picker
     * offers today, this week and this month as presets, so those readings are
     * still one click away and now bring the list with them.
     *
     * Still deliberately NOT narrowed by the search or the purpose filter.
     * Those two ask "which of these rows did I mean", where the window asks
     * "which stretch of the log am I reading" - and the pager already says how
     * many rows a search matched, so repeating that here would be a second
     * answer to a question nobody asked twice.
     *
     * Which three: the count, because it is the shape of the window; the people
     * behind it, because forty visits from twenty-five customers is a different
     * fortnight from forty visits from thirty-nine and the list itself cannot
     * show that without being read end to end; and the follow-ups promised,
     * because it is the only figure here that is work outstanding rather than
     * work done, which is what somebody scanning a visits list is usually about
     * to go and do something about. Products named and new customers were the
     * other candidates and were left to the dashboard - this screen is the log,
     * not the analysis of it, and a row of tiles that duplicates the dashboard
     * gives a reader no reason to have come here.
     *
     * @param  array{search: string, purpose: string, range: string, from: string, to: string}  $filters
     * @return array<int, DashboardStatData>
     */
    private function stats(User $viewer, array $filters): array
    {
        $now = $this->totals($viewer, $filters['from'], $filters['to']);
        $preceding = $this->precedingWindow($filters);

        /* No window, or one open at the back, has nothing of equal length
           before it, so every figure is compared against zero. `compare()`
           reads that as "nothing to measure against" and the tile prints so,
           which is the truthful answer for a reader looking at the whole log -
           the alternative, inventing some earlier stretch to hold up beside it,
           would be a percentage about a period nobody chose. */
        $before = $preceding === null
            ? ['visits' => 0, 'customers' => 0, 'follow_ups' => 0]
            : $this->totals($viewer, $preceding['from'], $preceding['to']);

        return [
            DashboardStatData::compare(
                'visits',
                /* Claiming a floor-wide count while showing a personal one
                   would be a quiet lie, so the label says which it is. */
                $viewer->can(Permission::VisitsViewAny->value) ? 'Total visits' : 'Visits you logged',
                $now['visits'],
                $before['visits'],
            ),
            DashboardStatData::compare('customers', 'Unique customers', $now['customers'], $before['customers']),
            DashboardStatData::compare('follow_ups', 'Follow-ups promised', $now['follow_ups'], $before['follow_ups']),
        ];
    }

    /**
     * The three figures for one window, in a single round trip.
     *
     * One query rather than three: they read the same rows behind the same
     * visibility split and the same two bounds on `visited_at`, which is
     * indexed, so the database can answer all three off one pass rather than
     * being asked to find the same rows again twice.
     *
     * `toBase()` because the aliases here - `visits`, `customers` - would
     * otherwise be read off a hydrated `Visit`, where a name that happens to
     * match a relation or an accessor resolves to that instead of to the
     * column. A plain row has no such opinions.
     *
     * @return array{visits: int, customers: int, follow_ups: int}
     */
    private function totals(User $viewer, string $from, string $to): array
    {
        $counted = $this->betweenDates($this->visible($viewer), $from, $to)
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('COUNT(DISTINCT visits.customer_id) AS customers')
            /* `COUNT` over a nullable column counts the rows that have one,
               which is exactly what a pencilled-in follow-up is. */
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
     * The equally long stretch of log immediately before the chosen window,
     * and how many days that is.
     *
     * @param  array{search: string, purpose: string, range: string, from: string, to: string}  $filters
     * @return array{days: int, from: string, to: string}|null
     */
    private function precedingWindow(array $filters): ?array
    {
        return DateWindow::preceding($filters['from'], $filters['to']);
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
     * A query narrowed to a window, either end of which may be blank for an
     * open one.
     *
     * Its own method because the list, the download and the figures above them
     * all have to draw the window the same way. They did not, briefly - the
     * tiles counted fixed windows of their own - and a screen whose figures
     * describe a different fortnight from the rows beneath them is the sort of
     * disagreement nobody notices until a number is quoted in a meeting.
     *
     * Both ends are dates, `visited_at` is a datetime, and the two ends
     * therefore need opposite treatment. The near end is the opening midnight,
     * which is the first instant of that day. The far end is written as `< the
     * following midnight` rather than `<= 23:59:59`: a window closing on the
     * 28th is meant to hold everything logged during the 28th, and the
     * second-precision reading quietly drops a visit stamped in the last second
     * of the day - and would drop rather more of them on any host storing
     * fractional seconds.
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
     * The line under the title on a printed export: which slice of the log
     * this is.
     *
     * Whose visits it holds is named first. A salesperson's printed log is not
     * a short month on the floor, and a sheet that does not say so is one
     * somebody will read as the whole showroom's.
     *
     * @param  array{search: string, purpose: string, range: string, from: string, to: string}  $filters
     */
    private function exportSubtitle(User $viewer, array $filters): string
    {
        $parts = [
            $viewer->can(Permission::VisitsViewAny->value)
                ? 'Every visit'
                : 'Visits logged by '.$viewer->name,
            /* The window is printed even when none was picked, where it reads
               "All dates": a sheet has nothing else on it to say how far back
               it reaches, and a full history and a fortnight somebody happened
               to pull are otherwise the same piece of paper. */
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
     * @return array{search: string, purpose: string, range: string, from: string, to: string}
     */
    private function filters(Request $request): array
    {
        $purpose = $request->string('purpose')->toString();
        [$range, $from, $to] = $this->window($request);

        return [
            'search' => $request->string('search')->trim()->toString(),
            'purpose' => in_array($purpose, VisitPurpose::values(), true) ? $purpose : '',
            /* The name of the window where one was named, blank where it was
               drawn on the calendar or not chosen at all. Only the picker cares
               which of the two it was - everything downstream reads the
               resolved dates - but it has to know, or a preset would come back
               from the server as a pair of dates and the button that produced
               it would stop reading "This month" the moment it was clicked. */
            'range' => $range,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * The date window the log is being read under: the name of the window
     * where one was named, and the pair of `Y-m-d` ends it resolves to, either
     * of which may be blank for an open end.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function window(Request $request): array
    {
        return DateWindow::fromRequest($request);
    }

    /**
     * The window as a sentence, for the calendar's closed button and for the
     * line under a printed export's title.
     *
     * @param  array{search: string, purpose: string, range: string, from: string, to: string}  $filters
     */
    private function windowLabel(array $filters): string
    {
        return DateWindow::label($filters['from'], $filters['to']);
    }
}
