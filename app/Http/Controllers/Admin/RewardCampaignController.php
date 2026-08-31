<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\RewardCampaignData;
use App\Enums\Permission;
use App\Exceptions\CampaignStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RewardCampaignRequest;
use App\Models\Reward;
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
            'catalogue' => $this->catalogue(),
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

        /* Three hops for one table: the attachment, the catalogue row it names
           and the products it is paired to. Loading them here is what keeps
           `CampaignRewardData::fromModel` from asking per row. */
        $campaign->load([
            'rewards.reward.product:id,name',
            'rewards.qualifyingProducts:id,name',
        ])->loadCount('sessions');

        $viewer = $request->user();

        return Inertia::render('admin/rewards/Form', [
            'campaign' => RewardCampaignData::fromModel($campaign, $this->pool->inventory($campaign)),
            /* Everything still on offer, plus whatever this campaign already
               holds - a retired reward stays pickable on the draft that had it
               before it was retired, or the form would drop a row on save. */
            'catalogue' => $this->catalogue($campaign),
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
     * The rewards the form may choose from.
     *
     * The active catalogue, plus anything this campaign already holds. A
     * reward retired after a draft picked it up has to stay in the list, or
     * reopening that draft would show a blank row and saving it would drop the
     * reward - see `RewardCampaignRequest::refuseRetiredRewards()`, which
     * refuses only newly added retired rewards for the same reason.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(?RewardCampaign $campaign = null): array
    {
        $held = $campaign?->rewards->pluck('reward_id')->all() ?? [];

        return Reward::query()
            ->with('product:id,name')
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $held))
            ->orderBy('name')
            ->get()
            ->map(fn (Reward $reward): array => [
                'id' => $reward->id,
                'name' => $reward->readableName(),
                'description' => $reward->description,
                'type' => $reward->type,
                'type_label' => $reward->type->label(),
                'product_id' => $reward->product_id,
                'product_name' => $reward->product?->name,
                'value_label' => $reward->readableValue(),
                'terms' => $reward->terms,
                'default_validity_days' => $reward->default_validity_days,
                'is_active' => $reward->is_active,
            ])
            ->all();
    }

    /**
     * The reward attachments, as the form sent them.
     *
     * Rewritten wholesale rather than diffed: this only ever runs on a draft,
     * where nothing points at an attachment yet, so replacing them is both
     * simpler and impossible to get subtly wrong.
     *
     * Nothing describing the reward is written here. The row names a catalogue
     * entry and carries only what the campaign decides - how many, how long,
     * and which products put somebody in the running.
     */
    private function writeRewards(RewardCampaign $campaign, RewardCampaignRequest $request): void
    {
        /* One query for the whole form rather than one per row, and the
           source of the fallback below. */
        $catalogue = Reward::query()
            ->whereIn('id', array_column($request->rewards(), 'reward_id'))
            ->get()
            ->keyBy('id');

        foreach ($request->rewards() as $reward) {
            $catalogueRow = $catalogue->get($reward['reward_id']);

            $attachment = $campaign->rewards()->create([
                'reward_id' => $reward['reward_id'],
                'quantity' => $reward['quantity'],
                /* The campaign's own deadline, falling back to whatever the
                   catalogue suggests. Copied down rather than read through at
                   win time, so retuning the catalogue later cannot move a
                   deadline this campaign has already promised. */
                'validity_days' => $reward['validity_days']
                    ?? $catalogueRow?->default_validity_days,
                'is_active' => true,
            ]);

            $productIds = array_values(array_unique(
                array_map('intval', $reward['qualifying_product_ids'] ?? []),
            ));

            if ($productIds !== []) {
                $attachment->qualifyingProducts()->sync($productIds);
            }
        }
    }
}
