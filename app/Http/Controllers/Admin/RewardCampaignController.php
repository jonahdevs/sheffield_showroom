<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\OptionData;
use App\Data\RewardCampaignData;
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

    # `RewardCampaignRequest` drops anything arriving under `rewards` for a published
    # campaign rather than refusing it, so a stale tab cannot rewrite the odds.
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

            # Drafts only. After publication the pool is written and the definitions are history.
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
            'message' => __('The :name draft has been deleted.', ['name' => $name]),
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

    # Rewritten wholesale rather than diffed: only ever runs on a draft, where nothing
    # points at an attachment yet.
    private function writeRewards(RewardCampaign $campaign, RewardCampaignRequest $request): void
    {
        $catalogue = Reward::query()
            ->whereIn('id', array_column($request->rewards(), 'reward_id'))
            ->get()
            ->keyBy('id');

        foreach ($request->rewards() as $reward) {
            $catalogueRow = $catalogue->get($reward['reward_id']);

            $attachment = $campaign->rewards()->create([
                'reward_id' => $reward['reward_id'],
                'quantity' => $reward['quantity'],
                # Copied down, never read through at win time, so retuning the catalogue
                # cannot move a deadline this campaign has already promised.
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
