<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\DashboardRangeData;
use App\Data\DashboardSliceData;
use App\Data\DashboardStatData;
use App\Data\DashboardTrendPointData;
use App\Data\RewardCampaignSummaryData;
use App\Data\RewardDrawerRowData;
use App\Data\RewardExpiringRowData;
use App\Data\RewardHeadlineData;
use App\Enums\CampaignStatus;
use App\Enums\RewardResultStatus;
use App\Http\Controllers\Controller;
use App\Models\CampaignReward;
use App\Models\RewardCampaign;
use App\Models\RewardPoolEntry;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use App\Services\Rewards\RewardPoolService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RewardOverviewController extends Controller
{
    private const DRAWER_ROWS = 5;

    private const PAST_CAMPAIGNS = 6;

    private const EXPIRING_WITHIN_DAYS = 14;

    private const EXPIRING_ROWS = 5;

    public function __construct(private readonly RewardPoolService $pool) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RewardCampaign::class);

        # One instant for the whole page: expiry is read off the calendar, so asking
        # "now" twice can count one reward claimable in the tiles and lapsed in the ring.
        $now = CarbonImmutable::now();
        $range = DashboardRangeData::fromRequest($request);

        $campaign = $this->currentCampaign($now);
        $inventory = $campaign === null ? [] : $this->pool->inventory($campaign);

        $current = $this->totals($range, $now);
        $before = $this->totals($range->previous(), $now);

        return Inertia::render('admin/rewards/Overview', [
            'range' => $range,
            'presets' => DashboardRangeData::options(),

            'headline' => $campaign === null
                ? null
                : RewardHeadlineData::fromModel($campaign, $inventory, $now),
            'drawer' => $campaign === null ? [] : $this->drawer($campaign, $inventory),

            'stats' => $this->stats($current, $before),
            'wins' => $this->trend($range),

            'collection_rate' => $this->collectionRate($current),
            'outcomes' => $this->outcomes($current),

            'expiring' => $this->expiring($now),
            'expiring_total' => $this->expiringTotal($now),
            'expiring_within_days' => self::EXPIRING_WITHIN_DAYS,

            'past' => $this->past($campaign?->id),
        ]);
    }

    # Filters in PHP rather than using the `running()` scope: the scope returns nothing for
    # an Active campaign past its end date, and the header must say "over" not "between
    # promotions" — the distinction `RewardHeadlineData::dormant_reason` exists for.
    private function currentCampaign(CarbonImmutable $now): ?RewardCampaign
    {
        $active = RewardCampaign::query()
            ->where('status', CampaignStatus::Active)
            ->orderBy('id')
            ->withCount('sessions')
            ->get();

        return $active->first(fn (RewardCampaign $campaign) => $campaign->isRunning($now))
            ?? $active->first();
    }

    /**
     * @param  array<int, array{available: int, claimed: int, void: int}>  $inventory
     * @return array<int, RewardDrawerRowData>
     */
    private function drawer(RewardCampaign $campaign, array $inventory): array
    {
        return $campaign->rewards()
            ->with('reward.product:id,name')
            ->get()
            ->map(fn (CampaignReward $attachment) => RewardDrawerRowData::fromModel(
                $attachment,
                $inventory[$attachment->id] ?? null,
            ))
            ->sortByDesc(fn (RewardDrawerRowData $row) => [$row->claimed_share, $row->claimed])
            ->take(self::DRAWER_ROWS)
            ->values()
            ->all();
    }

    /**
     * @param  array{turns: int, won: int, redeemed: int, unclaimed: int, lapsed: int, cancelled: int}  $current
     * @param  array{turns: int, won: int, redeemed: int, unclaimed: int, lapsed: int, cancelled: int}  $before
     * @return array<int, DashboardStatData>
     */
    private function stats(array $current, array $before): array
    {
        return [
            DashboardStatData::compare('turns', 'Turns given', $current['turns'], $before['turns']),
            DashboardStatData::compare('won', 'Rewards won', $current['won'], $before['won']),
            DashboardStatData::compare('redeemed', 'Redeemed', $current['redeemed'], $before['redeemed']),
            DashboardStatData::compare('unclaimed', 'Still to collect', $current['unclaimed'], $before['unclaimed']),
        ];
    }

    /**
     * Everything but turns is counted by `won_at`, never by redemption date: the wins and
     * redemptions are read side by side as a cohort rate, and counting collections by their
     * own date puts a February reward redeemed in March into March's numerator and nobody's
     * denominator.
     *
     * `unclaimed`/`lapsed` split by the calendar, not the status: `rewards:expire` sweeps
     * nightly and the date is what decides, so an unswept row must not read as outstanding.
     * The four states partition the wins exactly, so the ring adds up to the tile.
     *
     * `toBase()`: off a hydrated `ShuffleResult` an alias matching a relation or accessor
     * resolves to that instead of to the column.
     *
     * @return array{turns: int, won: int, redeemed: int, unclaimed: int, lapsed: int, cancelled: int}
     */
    private function totals(DashboardRangeData $range, CarbonImmutable $now): array
    {
        $turns = ShuffleSession::query()
            ->whereBetween('created_at', [$range->startsAt(), $range->endsAt()])
            ->count();

        $counted = ShuffleResult::query()
            ->whereBetween('won_at', [$range->startsAt(), $range->endsAt()])
            ->selectRaw('COUNT(*) AS won')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS redeemed', [
                RewardResultStatus::Redeemed->value,
            ])
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND (expires_at IS NULL OR expires_at >= ?) THEN 1 ELSE 0 END) AS unclaimed',
                [RewardResultStatus::Unredeemed->value, $now],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? OR (status = ? AND expires_at IS NOT NULL AND expires_at < ?) THEN 1 ELSE 0 END) AS lapsed',
                [RewardResultStatus::Expired->value, RewardResultStatus::Unredeemed->value, $now],
            )
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled', [
                RewardResultStatus::Cancelled->value,
            ])
            ->toBase()
            ->first();

        return [
            'turns' => $turns,
            'won' => (int) ($counted->won ?? 0),
            'redeemed' => (int) ($counted->redeemed ?? 0),
            'unclaimed' => (int) ($counted->unclaimed ?? 0),
            'lapsed' => (int) ($counted->lapsed ?? 0),
            'cancelled' => (int) ($counted->cancelled ?? 0),
        ];
    }

    /**
     * @return array<int, DashboardTrendPointData>
     */
    private function trend(DashboardRangeData $range): array
    {
        # `date()` rather than a format string: the one spelling MySQL and SQLite agree on.
        $counts = ShuffleResult::query()
            ->whereBetween('won_at', [$range->startsAt(), $range->endsAt()])
            ->selectRaw('date(shuffle_results.won_at) as day')
            ->selectRaw('count(*) as total')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy('day');

        $points = [];

        for ($day = $range->startsAt(); $day <= $range->endsAt(); $day = $day->addDay()) {
            $on = $day->format('Y-m-d');

            $points[] = new DashboardTrendPointData(
                date: $on,
                label: $day->format('j M'),
                visits: (int) ($counts[$on]->total ?? 0),
            );
        }

        return $points;
    }

    /**
     * @param  array{turns: int, won: int, redeemed: int, unclaimed: int, lapsed: int, cancelled: int}  $totals
     */
    private function collectionRate(array $totals): ?float
    {
        if ($totals['won'] === 0) {
            return null;
        }

        return round(($totals['redeemed'] / $totals['won']) * 100, 1);
    }

    /**
     * @param  array{turns: int, won: int, redeemed: int, unclaimed: int, lapsed: int, cancelled: int}  $totals
     * @return array<int, DashboardSliceData>
     */
    private function outcomes(array $totals): array
    {
        if ($totals['won'] === 0) {
            return [];
        }

        # Not `RewardResultStatus::cases()`: "lapsed" gathers both the swept rows and the
        # unswept ones whose date has passed, which are the same thing to everybody but the scheduler.
        $buckets = [
            ['redeemed', 'Redeemed', $totals['redeemed']],
            ['unclaimed', 'Still to collect', $totals['unclaimed']],
            ['lapsed', 'Lapsed uncollected', $totals['lapsed']],
            ['cancelled', 'Cancelled', $totals['cancelled']],
        ];

        $slices = [];

        foreach ($buckets as [$value, $label, $count]) {
            if ($count === 0) {
                continue;
            }

            $slices[] = new DashboardSliceData(
                value: $value,
                label: $label,
                count: $count,
                share: round(($count / $totals['won']) * 100, 1),
            );
        }

        return $slices;
    }

    /**
     * Deliberately not narrowed by the page's window: this panel is about the next
     * fortnight, so a January reward lapsing on Friday must show while reading March.
     *
     * @return array<int, RewardExpiringRowData>
     */
    private function expiring(CarbonImmutable $now): array
    {
        $rows = $this->closingSoon($now)
            ->join('shuffle_sessions', 'shuffle_sessions.id', '=', 'shuffle_results.shuffle_session_id')
            ->join('customers', 'customers.id', '=', 'shuffle_sessions.customer_id')
            ->orderBy('shuffle_results.expires_at')
            ->orderBy('shuffle_results.id')
            ->limit(self::EXPIRING_ROWS)
            ->toBase()
            ->get([
                'shuffle_results.id',
                'shuffle_results.code',
                'shuffle_results.expires_at',
                'customers.name as customer_name',
            ]);

        return $rows->map(function (object $row) use ($now) {
            $expiresAt = CarbonImmutable::parse($row->expires_at);

            return new RewardExpiringRowData(
                id: (int) $row->id,
                code: (string) $row->code,
                customer_name: (string) $row->customer_name,
                expires_on: $expiresAt->format('j M Y'),
                days_left: max(0, (int) $now->diffInDays($expiresAt)),
            );
        })->all();
    }

    private function expiringTotal(CarbonImmutable $now): int
    {
        return $this->closingSoon($now)->count();
    }

    /**
     * Status *and* calendar, the pair `ShuffleResult::isRedeemable()` asks: an unredeemed
     * row whose date passed this morning is gone too, whatever the unswept column says.
     *
     * @return Builder<ShuffleResult>
     */
    private function closingSoon(CarbonImmutable $now): Builder
    {
        return ShuffleResult::query()
            ->where('shuffle_results.status', RewardResultStatus::Unredeemed)
            ->whereNotNull('shuffle_results.expires_at')
            ->whereBetween('shuffle_results.expires_at', [
                $now,
                $now->addDays(self::EXPIRING_WITHIN_DAYS)->endOfDay(),
            ]);
    }

    /**
     * Over means completed, cancelled, *or* simply past its end date.
     *
     * @return array<int, RewardCampaignSummaryData>
     */
    private function past(?int $exceptId): array
    {
        $now = CarbonImmutable::now();

        $campaigns = RewardCampaign::query()
            ->where(fn (Builder $over) => $over
                ->whereIn('status', [CampaignStatus::Completed, CampaignStatus::Cancelled])
                ->orWhere(fn (Builder $lapsed) => $lapsed
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<', $now)))
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->latest('id')
            ->limit(self::PAST_CAMPAIGNS)
            ->get();

        if ($campaigns->isEmpty()) {
            return [];
        }

        $ids = $campaigns->pluck('id')->all();

        # Counts void and available units too — `loaded = available + claimed + void`.
        $loaded = RewardPoolEntry::query()
            ->whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id')
            ->selectRaw('count(*) as loaded')
            ->groupBy('campaign_id')
            ->toBase()
            ->get()
            ->keyBy('campaign_id');

        $results = ShuffleResult::query()
            ->join('shuffle_sessions', 'shuffle_sessions.id', '=', 'shuffle_results.shuffle_session_id')
            ->whereIn('shuffle_sessions.campaign_id', $ids)
            ->selectRaw('shuffle_sessions.campaign_id as campaign_id')
            ->selectRaw('count(*) as won')
            ->selectRaw('sum(case when shuffle_results.status = ? then 1 else 0 end) as redeemed', [
                RewardResultStatus::Redeemed->value,
            ])
            ->groupBy('shuffle_sessions.campaign_id')
            ->toBase()
            ->get()
            ->keyBy('campaign_id');

        return $campaigns->map(fn (RewardCampaign $campaign) => RewardCampaignSummaryData::fromModel(
            $campaign,
            (int) ($loaded[$campaign->id]->loaded ?? 0),
            (int) ($results[$campaign->id]->won ?? 0),
            (int) ($results[$campaign->id]->redeemed ?? 0),
        ))->all();
    }
}
