<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\RewardCampaignData;
use App\Enums\Permission;
use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Exceptions\CampaignStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RewardCampaignRequest;
use App\Models\RewardCampaign;
use App\Services\Rewards\CampaignService;
use App\Services\Rewards\RewardPoolService;
use App\Support\Http\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The promotions themselves.
 *
 * Everything that changes a campaign's state goes through `CampaignService`
 * rather than being written here, because those transitions carry rules a
 * controller cannot hold: publishing is one-way, only one campaign runs at a
 * time, and both have to be decided while the row is locked.
 */
class RewardCampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly RewardPoolService $pool,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RewardCampaign::class);

        $viewer = $request->user();

        $campaigns = RewardCampaign::query()
            ->withCount('sessions')
            /* Newest first: the one somebody wants is almost always the one
               they just made or the one running now. */
            ->latest('id')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(fn (RewardCampaign $campaign) => RewardCampaignData::fromModel(
                $campaign,
                $this->pool->inventory($campaign),
                withRewards: false,
            ));

        return Inertia::render('admin/rewards/Index', [
            'campaigns' => $campaigns,
            'page_sizes' => PageSize::OPTIONS,
            'can' => [
                'create' => $viewer->can('create', RewardCampaign::class),
                'update' => $viewer->can(Permission::RewardsCampaignsUpdate->value),
                'delete' => $viewer->can(Permission::RewardsCampaignsDelete->value),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', RewardCampaign::class);

        return Inertia::render('admin/rewards/Form', [
            'campaign' => null,
            'reward_types' => RewardType::options(),
            'value_units' => RewardValueUnit::options(),
            'can' => ['update' => true, 'publish' => false, 'delete' => false],
        ]);
    }

    /**
     * One screen for the campaign and its drawer.
     *
     * A published campaign opens the same form with its rewards locked - the
     * quantities are inventory now, and `RewardCampaignRequest` drops anything
     * arriving under `rewards` rather than refusing it, so a stale tab cannot
     * rewrite the odds.
     */
    public function edit(Request $request, RewardCampaign $campaign): Response
    {
        $this->authorize('view', $campaign);

        $campaign->load('rewards')->loadCount('sessions');

        $viewer = $request->user();

        return Inertia::render('admin/rewards/Form', [
            'campaign' => RewardCampaignData::fromModel($campaign, $this->pool->inventory($campaign)),
            'reward_types' => RewardType::options(),
            'value_units' => RewardValueUnit::options(),
            'can' => [
                'update' => $viewer->can('update', $campaign),
                'publish' => $viewer->can('publish', $campaign),
                'delete' => $viewer->can('delete', $campaign),
            ],
        ]);
    }

    public function store(RewardCampaignRequest $request): RedirectResponse
    {
        $campaign = DB::transaction(function () use ($request) {
            $campaign = new RewardCampaign($request->safe()->only([
                'name', 'description', 'starts_at', 'ends_at',
                'max_shuffles_per_customer', 'minimum_purchase_amount',
            ]));

            $campaign->created_by = $request->user()->id;
            $campaign->save();

            $this->writeRewards($campaign, $request);

            return $campaign;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been created. Publish it when the drawer is right.', ['name' => $campaign->name]),
        ]);

        return to_route('admin.rewards.edit', $campaign);
    }

    public function update(RewardCampaignRequest $request, RewardCampaign $campaign): RedirectResponse
    {
        DB::transaction(function () use ($request, $campaign) {
            $campaign->update($request->safe()->only([
                'name', 'description', 'starts_at', 'ends_at',
                'max_shuffles_per_customer', 'minimum_purchase_amount',
            ]));

            /* Only while it is a draft. After publication the pool has been
               written and the definitions behind it are history. */
            if ($request->editsRewards()) {
                $campaign->rewards()->delete();
                $this->writeRewards($campaign, $request);
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been saved.', ['name' => $campaign->name]),
        ]);

        return to_route('admin.rewards.edit', $campaign);
    }

    /**
     * Writes the pool and opens the doors.
     *
     * The one action here that cannot be undone, which is why it is its own
     * route and its own button rather than a checkbox on the form.
     */
    public function publish(RewardCampaign $campaign): RedirectResponse
    {
        $this->authorize('publish', $campaign);

        try {
            $loaded = $this->campaigns->publish($campaign);
        } catch (CampaignStateException $exception) {
            return back()->withErrors(['campaign' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count rewards are loaded and :name is live.', [
                'count' => $loaded,
                'name' => $campaign->name,
            ]),
        ]);

        return back();
    }

    /**
     * Starting, stopping and ending a campaign.
     *
     * One route rather than three, because they are one decision from the
     * screen's point of view - a row of buttons that each set a state - and
     * three near-identical actions would be three places to forget a check.
     */
    public function transition(Request $request, RewardCampaign $campaign): RedirectResponse
    {
        $this->authorize('update', $campaign);

        $to = $request->string('to')->toString();

        try {
            match ($to) {
                'active' => $this->campaigns->activate($campaign),
                'paused' => $this->campaigns->pause($campaign),
                'completed' => $this->campaigns->complete($campaign),
                'cancelled' => $this->campaigns->cancel($campaign),
                default => throw new CampaignStateException('That is not a state a campaign can be put into.'),
            };
        } catch (CampaignStateException $exception) {
            return back()->withErrors(['campaign' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name is now :state.', [
                'name' => $campaign->name,
                'state' => $campaign->refresh()->status->label(),
            ]),
        ]);

        return back();
    }

    /**
     * Only ever a way to tidy away a draft nobody used - the policy refuses a
     * published one, because a campaign with a pool has history.
     */
    public function destroy(RewardCampaign $campaign): RedirectResponse
    {
        $this->authorize('delete', $campaign);

        $name = $campaign->name;

        $campaign->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The :name draft has been deleted.', ['name' => $name]),
        ]);

        return to_route('admin.rewards.index');
    }

    /**
     * The reward definitions, as the form sent them.
     *
     * Rewritten wholesale rather than diffed: this only ever runs on a draft,
     * where nothing points at a definition yet, so replacing them is both
     * simpler and impossible to get subtly wrong.
     */
    private function writeRewards(RewardCampaign $campaign, RewardCampaignRequest $request): void
    {
        foreach ($request->rewards() as $reward) {
            $campaign->rewards()->create([
                'name' => $reward['name'],
                'description' => $reward['description'] ?? null,
                'type' => $reward['type'],
                'value' => $reward['value'] ?? null,
                'value_unit' => $reward['value_unit'] ?? null,
                'quantity' => $reward['quantity'],
                'validity_days' => $reward['validity_days'] ?? null,
                'terms' => $reward['terms'] ?? null,
                'is_active' => true,
            ]);
        }
    }
}
