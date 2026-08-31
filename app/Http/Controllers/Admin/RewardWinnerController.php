<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\DashboardRangeData;
use App\Data\DashboardStatData;
use App\Data\RewardWinnerRowData;
use App\Enums\RewardResultStatus;
use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Models\RewardCampaign;
use App\Models\ShuffleResult;
use App\Support\Http\DateWindow;
use App\Support\Http\PageSize;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every reward anybody has won, newest first.
 *
 * This is the screen the rest of the reward feature was missing. The campaigns
 * list says what was loaded into the drawer and how much of it is left;
 * Redeem answers "is this one code good" for a customer standing at the
 * counter. Neither of them could answer the question an administrator actually
 * asks - what has this customer won, what is still uncollected, and is anybody
 * coming back for it - because Redeem cannot be searched by anything but a
 * code somebody read off their phone, and a code is exactly what the person
 * asking the question does not have.
 *
 * Read-only. Handing a reward over is Redeem's job and is a different
 * permission; this screen deliberately has no button that changes anything, so
 * `rewards.view` is the whole of its authorisation.
 */
class RewardWinnerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ShuffleResult::class);

        $filters = $this->filters($request);

        $rewards = $this->filtered($filters)
            ->with(RewardWinnerRowData::RELATIONS)
            /* Newest first, and the id to break a tie - a busy Saturday puts
               several wins on the same second, and without it the pager
               repeats rows across pages. */
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
            /* Only campaigns that have actually handed something out. A draft
               nobody has played is a filter that can only ever return nothing,
               and a select full of them buries the two that matter. */
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
     * The three figures above the list, for the window the page is read under
     * and against the equally long window before it.
     *
     * All three count by when the reward was **won**, not by when anything
     * happened to it afterwards. That is what makes the first two readable
     * side by side as a redemption rate: "of the rewards won in February, this
     * many have since been collected" is a question about a cohort, and
     * counting redemptions by their own date instead would put a January
     * reward collected in February into February's numerator and nobody's
     * denominator.
     *
     * Deliberately not narrowed by the search, the campaign or the status
     * filter - those ask "which of these rows did I mean", where the window
     * asks "which stretch am I reading". Filtering the tiles by status would
     * be worse than redundant: it would make "Redeemed" and "Rewards won" show
     * the same figure whenever somebody clicked Redeemed.
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
            /* The only figure here that is work outstanding rather than work
               done, and the reason somebody scrolls this page: these are
               people with a promise still open. */
            DashboardStatData::compare(
                'outstanding',
                'Still to collect',
                $now['outstanding'],
                $before['outstanding'],
            ),
        ];
    }

    /**
     * The three figures for one window, in a single round trip.
     *
     * `toBase()` because the aliases would otherwise be read off a hydrated
     * `ShuffleResult`, where a name matching a relation or an accessor
     * resolves to that instead of to the column. A plain row has no such
     * opinions.
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
                /* Through the attachment to the catalogue: what kind of thing
                   a reward is belongs to the reward, not to the campaign's
                   copy of how many there were. */
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
     * The window, closed at the start of the day after `to` so that a reward
     * won at four in the afternoon on the last day is inside it.
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
     * What the page is being read under, all of it corrected rather than
     * refused - see `DateWindow` for why a list answers a mangled query string
     * with the list.
     *
     * The campaign is checked for being a number and nothing more; an id that
     * matches no campaign returns an empty list, which is the honest answer to
     * "show me campaign 900".
     *
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
