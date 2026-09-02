<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\OptionData;
use App\Data\RewardCampaignData;
use App\Enums\CampaignStatus;
use App\Enums\Permission;
use App\Exceptions\CampaignStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RewardCampaignRequest;
use App\Models\Product;
use App\Models\Reward;
use App\Models\RewardCampaign;
use App\Services\Rewards\CampaignService;
use App\Services\Rewards\RewardPoolService;
use App\Support\Http\PageSize;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

# Every state change goes through `CampaignService`, never written here: publishing is
# one-way and only one campaign runs at a time, and both must be decided under the row lock.
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
            'products' => $this->products(),
            'can' => ['update' => true, 'publish' => false, 'delete' => false],
        ]);
    }

    public function edit(Request $request, RewardCampaign $campaign): Response
    {
        $this->authorize('view', $campaign);

        # `withTrashed()` on the pairing is load-bearing, and the `:id,name` shorthand cannot
        # express it. Withdrawing a product leaves its `campaign_reward_product` row standing;
        # without this the pairing vanishes off the screen and the next save posts back what
        # was shown, unpairing it for good.
        $campaign->load([
            'rewards.reward.product:id,name',
            'rewards.qualifyingProducts' => fn (BelongsToMany $products) => $products
                ->withTrashed()
                ->select('products.id', 'products.name'),
        ])->loadCount('sessions');

        $viewer = $request->user();

        return Inertia::render('admin/rewards/Form', [
            'campaign' => RewardCampaignData::fromModel($campaign, $this->pool->inventory($campaign)),
            'catalogue' => $this->catalogue($campaign),
            'products' => $this->products(),
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
            # Spelled out rather than left to the column default, which a saved
            # model does not carry back: `writeRewards` reads the status to decide
            # whether the new attachments need units written with them.
            $campaign->status = CampaignStatus::Draft;
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

            $this->writeRewards($campaign, $request);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been saved.', ['name' => $campaign->name]),
        ]);

        return to_route('admin.rewards.edit', $campaign);
    }

    # Writes the pool and opens the doors. One-way — hence its own route, not a form checkbox.
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

    public function destroy(RewardCampaign $campaign): RedirectResponse
    {
        $this->authorize('delete', $campaign);

        $name = $campaign->name;

        $campaign->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been deleted.', ['name' => $name]),
        ]);

        return to_route('admin.rewards.index');
    }

    /**
     * The active catalogue *plus* whatever this campaign already holds: a reward retired
     * after a draft picked it up must stay pickable, or reopening the draft shows a blank
     * row and saving drops the reward. `RewardCampaignRequest::refuseRetiredRewards()`
     * refuses only newly added retired rewards, for the same reason.
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
     * Deliberately *not* widened with withdrawn products the way `catalogue()` is widened
     * with held rewards. An existing pairing is named through the combobox's `selected`
     * prop, which keeps the chip labelled without offering a withdrawn product to new rows.
     *
     * @return array<int, OptionData>
     */
    private function products(): array
    {
        return Product::query()
            ->orderBy('name')
            ->get()
            ->map(OptionData::fromProduct(...))
            ->all();
    }

    /**
     * A diff, never a rewrite: attachment ids are load-bearing.
     * `reward_pool_entries.campaign_reward_id` and the results behind them point at
     * these rows, so recreating a kept attachment would orphan real wins.
     */
    private function writeRewards(RewardCampaign $campaign, RewardCampaignRequest $request): void
    {
        $incoming = $request->rewards();
        $isPublished = $campaign->status->isPublished();
        $now = CarbonImmutable::now();

        $held = $campaign->rewards()->get()->keyBy('reward_id');

        $catalogue = Reward::query()
            ->whereIn('id', array_column($incoming, 'reward_id'))
            ->get()
            ->keyBy('id');

        $keptIds = [];

        foreach ($incoming as $reward) {
            $rewardId = (int) $reward['reward_id'];

            # Copied down, never read through at win time, so retuning the catalogue
            # cannot move a deadline this campaign has already promised.
            $validityDays = $reward['validity_days']
                ?? $catalogue->get($rewardId)?->default_validity_days;

            $attachment = $held->get($rewardId);

            if ($attachment === null) {
                $attachment = $campaign->rewards()->create([
                    'reward_id' => $rewardId,
                    'quantity' => (int) $reward['quantity'],
                    'validity_days' => $validityDays,
                    'is_active' => true,
                ]);

                # A reward added after publication needs its own units written or it
                # is a reward nobody can win. Per attachment, never `generate()`,
                # which would write a second pool for every attachment already here.
                if ($isPublished) {
                    $this->pool->writeUnits($campaign, $attachment, $now);
                }
            } else {
                $attachment->validity_days = $validityDays;

                # `quantity` is inventory once the pool is written: `loaded` never
                # falls, and `loaded = available + claimed + void` must keep
                # reconciling. A stale form is dropped here, not refused.
                if (! $isPublished) {
                    $attachment->quantity = (int) $reward['quantity'];
                }

                $attachment->save();
            }

            $attachment->qualifyingProducts()->sync(array_values(array_unique(
                array_map('intval', $reward['qualifying_product_ids'] ?? []),
            )));

            $keptIds[] = $attachment->id;
        }

        # Cascades its `reward_pool_entries` and `campaign_reward_product` rows.
        # `RewardCampaignRequest::refuseRemovingWonRewards` has already refused any
        # attachment carrying a claimed unit, which is the only kind kept for good.
        $campaign->rewards()->whereNotIn('id', $keptIds)->delete();
    }
}
