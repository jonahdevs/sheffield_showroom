<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\DashboardProductInterestData;
use App\Data\DashboardRangeData;
use App\Data\DashboardRespondentData;
use App\Data\DashboardSliceData;
use App\Data\DashboardStatData;
use App\Data\DashboardTrendPointData;
use App\Data\VisitRowData;
use App\Enums\CustomerSource;
use App\Enums\Permission;
use App\Enums\VisitPurpose;
use App\Exports\DashboardSummaryExport;
use App\Models\Product;
use App\Models\User;
use App\Models\Visit;
use App\Services\Documents\TableDocumentService;
use App\Support\Http\ExportResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The showroom at a glance: how many came in over a window, why, from where,
 * what they were shown, and who took them.
 *
 * Every panel counts in the database rather than in PHP. The dashboard is the
 * one screen that reads the whole log at once, and a page that pulled a
 * quarter's visits into memory to add them up would be the first thing to fall
 * over as the log grows.
 *
 * Every panel also runs through `visible()`, so a salesperson's dashboard
 * measures their own work on the same boundary their visits list draws.
 */
class DashboardController extends Controller
{
    /** Enough to see who the week belonged to, not a leaderboard of the whole floor. */
    private const TOP_PRODUCTS = 5;

    private const TOP_RESPONDENTS = 6;

    private const RECENT_VISITS = 6;

    public function index(Request $request): Response
    {
        $this->authorize(Permission::DashboardView->value);

        $viewer = $request->user();
        $range = DashboardRangeData::fromRequest($request);

        return Inertia::render('Dashboard', [
            ...$this->panels($viewer, $range),
            'recent' => $this->recentVisits($viewer, $range),
            /* Only the formats this host can actually produce. Paper needs a
               headless browser the machine may not have, and a download button
               that always fails is worse than one format fewer. */
            'formats' => $this->formats(),
            /* A salesperson is looking at their own numbers, not the floor's;
               saying so stops a quiet personal week reading as a quiet
               showroom. */
            'scoped_to_own' => ! $viewer->can(Permission::VisitsViewAny->value),
            'can' => [
                'view_visits' => $viewer->can('viewAny', Visit::class),
            ],
        ]);
    }

    /**
     * The same figures the page shows, as a spreadsheet or as paper.
     *
     * No permission of its own beyond opening the dashboard: this file is what
     * the reader is already looking at, narrowed by the same window and the
     * same visibility, so a second gate would only refuse them a copy of what
     * is on their screen.
     */
    public function export(Request $request): BinaryFileResponse|HttpResponse|RedirectResponse
    {
        $this->authorize(Permission::DashboardView->value);

        $range = DashboardRangeData::fromRequest($request);
        $panels = $this->panels($request->user(), $range);

        $export = new DashboardSummaryExport(
            $range,
            $panels['stats'],
            $panels['trend'],
            $panels['purposes'],
            $panels['sources'],
            $panels['products'],
            $panels['respondents'],
        );

        return ExportResponse::make(
            $export,
            'showroom-dashboard-'.$range->from.'-to-'.$range->to,
            $request->string('format')->toString(),
            'Showroom dashboard',
            $range->label,
        );
    }

    /**
     * Every panel's figures for one window.
     *
     * Shared by the screen and the download rather than gathered twice. A file
     * taken off a screen has to say what the screen said, and two passes at the
     * database - even an instant apart - is how a total in a spreadsheet ends
     * up disagreeing with the tile it came from.
     *
     * @return array{
     *     range: DashboardRangeData,
     *     stats: array<int, DashboardStatData>,
     *     trend: array<int, DashboardTrendPointData>,
     *     purposes: array<int, DashboardSliceData>,
     *     sources: array<int, DashboardSliceData>,
     *     products: array<int, DashboardProductInterestData>,
     *     respondents: array<int, DashboardRespondentData>,
     * }
     */
    private function panels(User $viewer, DashboardRangeData $range): array
    {
        return [
            'range' => $range,
            'stats' => $this->stats($viewer, $range),
            'trend' => $this->trend($viewer, $range),
            'purposes' => $this->breakdown($viewer, $range, 'purpose', VisitPurpose::class),
            'sources' => $this->breakdown($viewer, $range, 'source', CustomerSource::class),
            'products' => $this->topProducts($viewer, $range),
            'respondents' => $this->respondents($viewer, $range),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function formats(): array
    {
        return array_values(array_filter(
            ExportResponse::FORMATS,
            fn (string $format) => $format !== 'pdf' || TableDocumentService::available(),
        ));
    }

    /**
     * The KPI row, each figure beside the same figure from the window before.
     *
     * @return array<int, DashboardStatData>
     */
    private function stats(User $viewer, DashboardRangeData $range): array
    {
        $now = $this->totals($viewer, $range);
        $before = $this->totals($viewer, $range->previous());

        return [
            DashboardStatData::compare('visits', 'Total visits', $now['visits'], $before['visits']),
            DashboardStatData::compare('customers', 'Unique customers', $now['customers'], $before['customers']),
            DashboardStatData::compare('new_customers', 'New customers', $now['new_customers'], $before['new_customers']),
            DashboardStatData::compare('product_interests', 'Products interested in', $now['product_interests'], $before['product_interests']),
        ];
    }

    /**
     * @return array{visits: int, customers: int, new_customers: int, product_interests: int}
     */
    private function totals(User $viewer, DashboardRangeData $range): array
    {
        /* Both in one round trip. They read the same rows behind the same
           filter - the visits, and the people behind them - and forty visits
           from twenty-five people is a different floor than forty from
           thirty-nine, so the pair is the answer rather than two answers. */
        $counted = $this->within($viewer, $range)
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('COUNT(DISTINCT visits.customer_id) AS customers')
            ->first();

        return [
            'visits' => (int) ($counted?->visits ?? 0),
            'customers' => (int) ($counted?->customers ?? 0),

            /* How many of those had never been before. There is deliberately
               no returning figure beside it - it would be this subtracted
               from the count above, which is the tile immediately to its
               left, and a row that carries a total and both its halves is
               three numbers doing two numbers' work. */
            'new_customers' => $this->newCustomers($viewer, $range),

            'product_interests' => $this->productInterests($viewer, $range),
        ];
    }

    /**
     * Customers whose first visit in this viewer's reach falls inside the
     * window.
     *
     * "First" is measured against the visits this viewer may see, not against
     * the customer's whole history. For a salesperson the question is who is
     * new to them; a customer another branch of the floor met last year is
     * still somebody they are meeting for the first time.
     */
    private function newCustomers(User $viewer, DashboardRangeData $range): int
    {
        $startsAt = $range->startsAt();
        $scoped = $this->scopedToOwn($viewer);

        return $this->within($viewer, $range)
            ->whereNotExists(fn (QueryBuilder $earlier) => $earlier
                ->select(DB::raw(1))
                ->from('visits as earlier')
                ->whereColumn('earlier.customer_id', 'visits.customer_id')
                /* The soft-delete scope does not reach inside a raw subquery,
                   so a removed visit would otherwise still make somebody a
                   returning customer. */
                ->whereNull('earlier.deleted_at')
                ->where('earlier.visited_at', '<', $startsAt)
                ->when($scoped, fn (QueryBuilder $query) => $query->where('earlier.created_by', $viewer->id)))
            ->distinct()
            ->count('visits.customer_id');
    }

    /**
     * How many products were named across the window's visits.
     *
     * Attachments rather than distinct products: this is the appetite the
     * floor saw, and one product asked after on thirty visits is thirty
     * conversations, not one.
     */
    private function productInterests(User $viewer, DashboardRangeData $range): int
    {
        return $this->within($viewer, $range)
            ->join('product_visit', 'product_visit.visit_id', '=', 'visits.id')
            ->count();
    }

    /**
     * Visits per day, with the empty days filled back in.
     *
     * @return array<int, DashboardTrendPointData>
     */
    private function trend(User $viewer, DashboardRangeData $range): array
    {
        /* `date()` rather than a format string: it is the one spelling both
           MySQL, which runs the showroom, and SQLite, which runs the tests,
           agree on. */
        $counts = $this->within($viewer, $range)
            ->selectRaw('date(visits.visited_at) as day')
            ->selectRaw('count(*) as total')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->pluck('total', 'day');

        $points = [];

        for ($day = $range->startsAt(); $day <= $range->endsAt(); $day = $day->addDay()) {
            $on = $day->format('Y-m-d');

            $points[] = new DashboardTrendPointData(
                date: $on,
                label: $day->format('j M'),
                visits: (int) ($counts[$on] ?? 0),
            );
        }

        return $points;
    }

    /**
     * A donut's wedges: how the window's visits divide across one enum column.
     *
     * Only the values something landed on come back. Nine purposes with four
     * of them at zero is a legend that is mostly nothing, and the wedges that
     * matter get lost in it.
     *
     * @param  'purpose'|'source'  $column
     * @param  class-string<VisitPurpose|CustomerSource>  $enum
     * @return array<int, DashboardSliceData>
     */
    private function breakdown(User $viewer, DashboardRangeData $range, string $column, string $enum): array
    {
        $counts = $this->within($viewer, $range)
            ->selectRaw("visits.{$column} as bucket")
            ->selectRaw('count(*) as total')
            ->groupBy('bucket')
            ->toBase()
            ->get()
            ->pluck('total', 'bucket');

        $total = (int) $counts->sum();

        if ($total === 0) {
            return [];
        }

        $slices = [];

        foreach ($enum::cases() as $case) {
            $count = (int) ($counts[$case->value] ?? 0);

            if ($count === 0) {
                continue;
            }

            $slices[] = new DashboardSliceData(
                value: $case->value,
                label: $case->label(),
                count: $count,
                share: round(($count / $total) * 100, 1),
            );
        }

        /* Biggest wedge first, and the enum's declaration order to settle a
           tie. The legend is read top to bottom against a ring read clockwise
           from twelve, and the two only line up if both run by size. */
        usort($slices, fn (DashboardSliceData $a, DashboardSliceData $b) => $b->count <=> $a->count);

        return $slices;
    }

    /**
     * The products named on the most visits in the window.
     *
     * Two queries rather than a count against the whole catalogue: the pivot
     * is where the answer lives, and ranking there means the catalogue is only
     * read for the handful of rows that won.
     *
     * @return array<int, DashboardProductInterestData>
     */
    private function topProducts(User $viewer, DashboardRangeData $range): array
    {
        $ranked = $this->within($viewer, $range)
            ->join('product_visit', 'product_visit.visit_id', '=', 'visits.id')
            ->selectRaw('product_visit.product_id as product_id')
            ->selectRaw('count(*) as interest')
            ->groupBy('product_id')
            ->orderByDesc('interest')
            ->orderBy('product_id')
            ->limit(self::TOP_PRODUCTS)
            ->toBase()
            ->get();

        if ($ranked->isEmpty()) {
            return [];
        }

        $products = Product::query()
            /* `image_path` among them, or `imageUrl()` has nothing to build
               the thumbnail from and every row comes back blank. */
            ->whereIn('id', $ranked->pluck('product_id')->all())
            ->get(['id', 'name', 'image_path'])
            ->keyBy('id');

        $top = [];

        foreach ($ranked as $row) {
            $product = $products->get((int) $row->product_id);

            /* A product retired since the visit is soft deleted and drops out
               here. The bar would have nothing to name, and resurrecting it
               for a chart is not worth undoing the removal for. */
            if ($product === null) {
                continue;
            }

            $top[] = DashboardProductInterestData::fromModel($product, (int) $row->interest);
        }

        return $top;
    }

    /**
     * Who took the window's visits, and what came of them.
     *
     * Falls back to whoever logged the visit, the same way the visits list
     * does, so a row recorded before the respondent was asked for still counts
     * towards somebody rather than vanishing into an unnamed bucket.
     *
     * @return array<int, DashboardRespondentData>
     */
    private function respondents(User $viewer, DashboardRangeData $range): array
    {
        $rows = $this->within($viewer, $range)
            ->leftJoin('users', 'users.id', '=', 'visits.created_by')
            ->selectRaw("coalesce(nullif(visits.respondent, ''), users.name, ?) as respondent_name", ['Unattributed'])
            ->selectRaw('count(*) as visits_count')
            ->selectRaw('count(distinct visits.customer_id) as customers_count')
            /* `count` over a nullable column counts the rows that have one,
               which is exactly what a pencilled-in follow-up is. */
            ->selectRaw('count(visits.expected_follow_up_on) as follow_ups_count')
            ->groupBy('respondent_name')
            ->orderByDesc('visits_count')
            ->orderBy('respondent_name')
            ->limit(self::TOP_RESPONDENTS)
            ->toBase()
            ->get();

        return $rows->map(fn (object $row) => new DashboardRespondentData(
            name: (string) $row->respondent_name,
            visits: (int) $row->visits_count,
            customers: (int) $row->customers_count,
            follow_ups: (int) $row->follow_ups_count,
        ))->all();
    }

    /**
     * The last few calls, as the visits list would show them.
     *
     * `VisitRowData` rather than a shape of its own: the panel names the same
     * things a row on the list names, and two objects describing one visit is
     * two places for the fallbacks to disagree.
     *
     * @return array<int, VisitRowData>
     */
    private function recentVisits(User $viewer, DashboardRangeData $range): array
    {
        return $this->within($viewer, $range)
            ->with(['customer', 'creator', 'products:id,name'])
            ->orderByDesc('visited_at')
            /* The id to break a tie, or a handful of visits filed on the same
               round hour come back in whatever order the table hands them
               over. */
            ->orderByDesc('id')
            ->limit(self::RECENT_VISITS)
            ->get()
            ->map(VisitRowData::fromModel(...))
            ->all();
    }

    /**
     * The visits this user is allowed to see at all, narrowed to the window.
     *
     * @return Builder<Visit>
     */
    private function within(User $viewer, DashboardRangeData $range): Builder
    {
        return $this->visible($viewer)
            ->whereBetween('visits.visited_at', [$range->startsAt(), $range->endsAt()]);
    }

    /**
     * The same boundary the visits list draws: `visits.view.any` is the whole
     * floor, anything less is what this person logged themselves.
     *
     * Qualified rather than leaning on the model's `loggedBy` scope, because
     * half the panels here join `products`, which carries a `created_by` of
     * its own - unqualified, the column is ambiguous and the query fails.
     *
     * @return Builder<Visit>
     */
    private function visible(User $viewer): Builder
    {
        return Visit::query()->when(
            $this->scopedToOwn($viewer),
            fn (Builder $query) => $query->where('visits.created_by', $viewer->id),
        );
    }

    private function scopedToOwn(User $viewer): bool
    {
        return ! $viewer->can(Permission::VisitsViewAny->value);
    }
}
