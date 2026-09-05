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
use App\Enums\VisitorType;
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
 * The showroom at a glance. Every panel counts in the database and runs through
 * `visible()`, so a salesperson's dashboard draws the same boundary their visits
 * list does.
 */
class DashboardController extends Controller
{
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
            'presets' => DashboardRangeData::options(),
            'formats' => $this->formats(),
            'scoped_to_own' => ! $viewer->can(Permission::VisitsViewAny->value),
            'can' => [
                'view_visits' => $viewer->can('viewAny', Visit::class),
            ],
        ]);
    }

    /**
     * No permission of its own beyond opening the dashboard: the file is narrowed by
     * the same window and the same visibility as the screen the reader already has.
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
        $counted = $this->within($viewer, $range)
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw(Visit::customerCount().' AS customers')
            ->first();

        return [
            'visits' => (int) ($counted?->visits ?? 0),
            'customers' => (int) ($counted?->customers ?? 0),
            'new_customers' => $this->newCustomers($viewer, $range),
            'product_interests' => $this->productInterests($viewer, $range),
        ];
    }

    /**
     * "First" is measured against the visits this viewer may see, not the customer's
     * whole history - for a salesperson the question is who is new to them.
     */
    private function newCustomers(User $viewer, DashboardRangeData $range): int
    {
        $startsAt = $range->startsAt();
        $scoped = $this->scopedToOwn($viewer);

        return $this->within($viewer, $range)
            # A courier's first call is not a new customer.
            ->forVisitorType(VisitorType::Customer)
            ->whereNotExists(fn (QueryBuilder $earlier) => $earlier
                ->select(DB::raw(1))
                ->from('visits as earlier')
                ->whereColumn('earlier.customer_id', 'visits.customer_id')
                # The soft-delete scope does not reach inside a raw subquery, so a
                # removed visit would otherwise still make somebody a returning customer.
                ->whereNull('earlier.deleted_at')
                ->where('earlier.visited_at', '<', $startsAt)
                ->when($scoped, fn (QueryBuilder $query) => $query->where('earlier.created_by', $viewer->id)))
            ->distinct()
            ->count('visits.customer_id');
    }

    private function productInterests(User $viewer, DashboardRangeData $range): int
    {
        return $this->within($viewer, $range)
            ->join('product_visit', 'product_visit.visit_id', '=', 'visits.id')
            ->count();
    }

    /**
     * @return array<int, DashboardTrendPointData>
     */
    private function trend(User $viewer, DashboardRangeData $range): array
    {
        # `date()` rather than a format string: the one spelling both MySQL, which
        # runs the showroom, and SQLite, which runs the tests, agree on.
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

        # Walks the buckets the query actually returned rather than the enum's
        # cases: `visits.purpose` is free text, so a reason somebody typed has
        # no case to match and iterating the enum would drop it from the chart
        # while still counting it in the total the shares divide by.
        foreach ($counts as $bucket => $count) {
            $bucket = (string) $bucket;
            $count = (int) $count;

            if ($bucket === '' || $count === 0) {
                continue;
            }

            $case = $enum::tryFrom($bucket);

            $slices[] = new DashboardSliceData(
                value: $bucket,
                label: $case?->label() ?? $bucket,
                count: $count,
                share: round(($count / $total) * 100, 1),
            );
        }

        usort($slices, fn (DashboardSliceData $a, DashboardSliceData $b) => $b->count <=> $a->count);

        return $slices;
    }

    /**
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

        # `image_path` has to be among the columns, or `imageUrl()` has nothing to
        # build the thumbnail from and every row comes back blank.
        $products = Product::query()
            ->whereIn('id', $ranked->pluck('product_id')->all())
            ->get(['id', 'name', 'image_path'])
            ->keyBy('id');

        $top = [];

        foreach ($ranked as $row) {
            $product = $products->get((int) $row->product_id);

            if ($product === null) {
                continue;
            }

            $top[] = DashboardProductInterestData::fromModel($product, (int) $row->interest);
        }

        return $top;
    }

    /**
     * Falls back to whoever logged the visit, the same way the visits list does, so a
     * row recorded before the respondent was asked for still counts towards somebody.
     *
     * @return array<int, DashboardRespondentData>
     */
    private function respondents(User $viewer, DashboardRangeData $range): array
    {
        $rows = $this->within($viewer, $range)
            ->leftJoin('users', 'users.id', '=', 'visits.created_by')
            ->selectRaw("coalesce(nullif(visits.respondent, ''), users.name, ?) as respondent_name", ['Unattributed'])
            ->selectRaw('count(*) as visits_count')
            ->selectRaw(Visit::customerCount().' as customers_count')
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
     * @return array<int, VisitRowData>
     */
    private function recentVisits(User $viewer, DashboardRangeData $range): array
    {
        return $this->within($viewer, $range)
            ->with(['customer', 'creator', 'products:id,name'])
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_VISITS)
            ->get()
            ->map(VisitRowData::fromModel(...))
            ->all();
    }

    /**
     * @return Builder<Visit>
     */
    private function within(User $viewer, DashboardRangeData $range): Builder
    {
        return $this->visible($viewer)
            ->whereBetween('visits.visited_at', [$range->startsAt(), $range->endsAt()]);
    }

    /**
     * The same boundary the visits list draws: `visits.view.any` is the whole floor,
     * anything less is what this person logged themselves.
     *
     * `visits.created_by` stays qualified rather than leaning on the model's `loggedBy`
     * scope: half the panels join `products`, which has a `created_by` of its own, and
     * unqualified the column is ambiguous and the query fails.
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
