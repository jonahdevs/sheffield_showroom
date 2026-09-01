<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\OptionData;
use App\Data\RewardData;
use App\Enums\Permission;
use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RewardRequest;
use App\Models\Product;
use App\Models\Reward;
use App\Support\Http\PageSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nothing here can reach a running promotion: `campaign_rewards` holds its own copy of
 * the deadline and `shuffle_results.expires_at` is stamped at the win. `reward_id` is
 * `restrictOnDelete`, so retiring with `is_active = false` is the answer to a reward that
 * has gone out of favour, never deletion.
 */
class RewardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Reward::class);

        $viewer = $request->user();
        $search = $request->string('search')->trim()->toString();
        $type = RewardType::tryFrom($request->string('type')->trim()->toString());
        $state = $this->state($request);

        $rewards = Reward::query()
            ->withCount('attachments')
            # `image_path` must be selected: `Product::imageUrl()` builds the link off that
            # column, and `id,name` alone hands it a model with the column missing.
            ->with('product:id,name,image_path')
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->when($state !== null, fn (Builder $query) => $query->where('is_active', $state))
            ->orderBy('name')
            # Names are not unique, so name alone is an unstable sort and the pager repeats rows.
            ->orderBy('id')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(RewardData::fromModel(...));

        return Inertia::render('admin/rewards/catalogue/Index', [
            'rewards' => $rewards,
            'filters' => [
                'search' => $search,
                'type' => $type?->value ?? '',
                'state' => $request->string('state')->trim()->toString(),
            ],
            'types' => RewardType::options(),
            'page_sizes' => PageSize::OPTIONS,
            'can' => [
                'create' => $viewer->can('create', Reward::class),
                'update' => $viewer->can(Permission::RewardsCatalogueUpdate->value),
                'delete' => $viewer->can(Permission::RewardsCatalogueDelete->value),
            ],
        ]);
    }

    private function state(Request $request): ?bool
    {
        return match ($request->string('state')->trim()->toString()) {
            'active' => true,
            'retired' => false,
            default => null,
        };
    }

    public function create(): Response
    {
        $this->authorize('create', Reward::class);

        return Inertia::render('admin/rewards/catalogue/Form', [
            'reward' => null,
            'types' => RewardType::options(),
            'units' => RewardValueUnit::options(),
            'products' => $this->products(),
            'can' => ['update' => true, 'delete' => false],
        ]);
    }

    # Authorized on `view`: `rewards.view` alone opens it, read-only when `can.update` is false.

    public function edit(Request $request, Reward $reward): Response
    {
        $this->authorize('view', $reward);

        $reward->loadCount('attachments')->load('product:id,name,image_path');

        $viewer = $request->user();

        return Inertia::render('admin/rewards/catalogue/Form', [
            'reward' => RewardData::fromModel($reward),
            'types' => RewardType::options(),
            'units' => RewardValueUnit::options(),
            'products' => $this->products(),
            'can' => [
                'update' => $viewer->can('update', $reward),
                'delete' => $viewer->can('delete', $reward),
            ],
        ]);
    }

    public function store(RewardRequest $request): RedirectResponse
    {
        $reward = new Reward($request->safe()->only([
            'name', 'description', 'type', 'product_id', 'value',
            'value_unit', 'terms', 'default_validity_days', 'is_active',
        ]));

        $reward->created_by = $request->user()->id;
        $reward->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been added to the catalogue.', ['name' => $reward->name]),
        ]);

        return to_route('admin.rewards.catalogue.index');
    }

    public function update(RewardRequest $request, Reward $reward): RedirectResponse
    {
        $reward->fill($request->safe()->only([
            'name', 'description', 'type', 'product_id', 'value',
            'value_unit', 'terms', 'default_validity_days', 'is_active',
        ]));

        # `RewardRequest` prohibits `product_id` for every non-product type, so the form
        # sends nothing rather than a null - without this the row keeps pointing at an oven
        # it no longer hands over.
        if (! $reward->type->isProduct()) {
            $reward->product_id = null;
        }

        $reward->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been saved.', ['name' => $reward->name]),
        ]);

        return to_route('admin.rewards.catalogue.index');
    }

    # Unattached rewards only: the policy refuses an attached one so `restrictOnDelete`
    # never has to answer with an integrity error.

    public function destroy(Reward $reward): RedirectResponse
    {
        $this->authorize('delete', $reward);

        $name = $reward->readableName();

        $reward->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been removed from the catalogue.', ['name' => $name]),
        ]);

        return back();
    }

    /**
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
}
