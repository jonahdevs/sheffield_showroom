<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\DashboardRangeData;
use App\Data\DashboardStatData;
use App\Data\RewardWinnerRowData;
use App\Data\ShuffleRewardData;
use App\Enums\RewardResultStatus;
use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Models\RewardCampaign;
use App\Models\ShuffleResult;
use App\Services\Rewards\RewardRedemptionService;
use App\Support\Http\DateWindow;
use App\Support\Http\PageSize;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RewardWinnerController extends Controller
{
    public function __construct(private readonly RewardRedemptionService $redemptions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ShuffleResult::class);

        $filters = $this->filters($request);

        $rewards = $this->filtered($filters)
            ->with(RewardWinnerRowData::RELATIONS)
            # The id breaks ties; wins land on the same second and the pager repeats rows.
            ->orderByDesc('won_at')
            ->orderByDesc('id')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(RewardWinnerRowData::fromModel(...));

        return Inertia::render('admin/rewards/Winners', [
            'rewards' => $rewards,
            'filters' => $filters,
            'date_label' => DateWindow::label($filters['from'], $filters['to']),
            'presets' => DashboardRangeData::options(),
            'window_days' => DateWindow::preceding($filters['from'], $filters['to'])['days'] ?? null,
            'stats' => $this->stats($filters),
            'redeem' => $this->lookup($request),
            'campaigns' => RewardCampaign::query()
                ->whereHas('sessions.result')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (RewardCampaign $campaign) => [
                    'value' => (string) $campaign->id,
                    'label' => $campaign->name,
                ])
                ->all(),
            'types' => RewardType::options(),
            'statuses' => RewardResultStatus::options(),
            'page_sizes' => PageSize::OPTIONS,
        ]);
    }

    /**
     * The counter is a dialog on this page rather than a screen of its own, so the code it
     * was asked about rides in the query string beside the list's own filters - the screen
     * can be reloaded, or read back over the phone, without losing what was found.
     *
     * @return array{code: string, searched: bool, reward: ShuffleRewardData|null, can_redeem: bool}
     */
    private function lookup(Request $request): array
    {
        $code = $request->string('redeem')->trim()->toString();
        $result = $code === '' ? null : $this->redemptions->find($code);

        return [
            'code' => $code,
            'searched' => $code !== '',
            'reward' => $result === null
                ? null
                : ShuffleRewardData::fromModel($result, withCustomer: true),
            'can_redeem' => $result !== null && $request->user()->can('redeem', $result),
        ];
    }

    /**
     * All three count by `won_at`, never by when anything happened afterwards — that is
     * what makes the first two readable as a cohort redemption rate.
     *
     * Deliberately not narrowed by search, campaign or status: filtering the tiles by
     * status would make "Redeemed" and "Rewards won" equal whenever Redeemed was clicked.
     *
     * @param  array{search: string, campaign: string, type: string, status: string, range: string, from: string, to: string}  $filters
     * @return array<int, DashboardStatData>
     */
    private function stats(array $filters): array
    {
        $now = $this->totals($filters['from'], $filters['to']);
        $preceding = DateWindow::preceding($filters['from'], $filters['to']);

        $before = $preceding === null
            ? ['won' => 0, 'redeemed' => 0, 'outstanding' => 0]
            : $this->totals($preceding['from'], $preceding['to']);

        return [
            DashboardStatData::compare('won', 'Rewards won', $now['won'], $before['won']),
            DashboardStatData::compare('redeemed', 'Collected', $now['redeemed'], $before['redeemed']),
            DashboardStatData::compare(
                'outstanding',
                'Still to collect',
                $now['outstanding'],
                $before['outstanding'],
            ),
        ];
    }

    /**
     * `toBase()`: off a hydrated `ShuffleResult` an alias matching a relation or accessor
     * resolves to that instead of to the column.
     *
     * @return array{won: int, redeemed: int, outstanding: int}
     */
    private function totals(string $from, string $to): array
    {
        $counted = $this->betweenDates(ShuffleResult::query(), $from, $to)
            ->selectRaw('COUNT(*) AS won')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS redeemed', [
                RewardResultStatus::Redeemed->value,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS outstanding', [
                RewardResultStatus::Unredeemed->value,
            ])
            ->toBase()
            ->first();

        return [
            'won' => (int) ($counted?->won ?? 0),
            'redeemed' => (int) ($counted?->redeemed ?? 0),
            'outstanding' => (int) ($counted?->outstanding ?? 0),
        ];
    }

    /**
     * @param  array{search: string, campaign: string, type: string, status: string, range: string, from: string, to: string}  $filters
     * @return Builder<ShuffleResult>
     */
    private function filtered(array $filters): Builder
    {
        $query = ShuffleResult::query()
            ->when(
                $filters['search'] !== '',
                fn (Builder $query) => $query->search($filters['search']),
            )
            ->when(
                $filters['campaign'] !== '',
                fn (Builder $query) => $query->whereHas(
                    'session',
                    fn (Builder $session) => $session->where('campaign_id', (int) $filters['campaign']),
                ),
            )
            ->when(
                $filters['type'] !== '',
                # Through the attachment to the catalogue: the type belongs to the reward,
                # not to the campaign's copy of it.
                fn (Builder $query) => $query->whereHas(
                    'poolEntry.reward.reward',
                    fn (Builder $reward) => $reward->where('type', $filters['type']),
                ),
            )
            ->when(
                $filters['status'] !== '',
                fn (Builder $query) => $query->where('status', $filters['status']),
            );

        return $this->betweenDates($query, $filters['from'], $filters['to']);
    }

    /**
     * Closed at the start of the day *after* `to`, so a win during the last day is inside it.
     *
     * @param  Builder<ShuffleResult>  $query
     * @return Builder<ShuffleResult>
     */
    private function betweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->when(
                $from !== '',
                fn (Builder $query) => $query->where(
                    'shuffle_results.won_at',
                    '>=',
                    CarbonImmutable::parse($from)->startOfDay(),
                ),
            )
            ->when(
                $to !== '',
                fn (Builder $query) => $query->where(
                    'shuffle_results.won_at',
                    '<',
                    CarbonImmutable::parse($to)->addDay()->startOfDay(),
                ),
            );
    }

    /**
     * @return array{search: string, campaign: string, type: string, status: string, range: string, from: string, to: string}
     */
    private function filters(Request $request): array
    {
        $campaign = $request->string('campaign')->toString();
        $type = $request->string('type')->toString();
        $status = $request->string('status')->toString();
        [$range, $from, $to] = DateWindow::fromRequest($request);

        return [
            'search' => $request->string('search')->trim()->toString(),
            'campaign' => ctype_digit($campaign) ? $campaign : '',
            'type' => in_array($type, RewardType::values(), true) ? $type : '',
            'status' => in_array($status, RewardResultStatus::values(), true) ? $status : '',
            'range' => $range,
            'from' => $from,
            'to' => $to,
        ];
    }
}
