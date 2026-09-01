<?php

use App\Enums\Permission;
use App\Enums\PurchaseStatus;
use App\Enums\RewardResultStatus;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\RewardCampaign;
use App\Models\RewardPoolEntry;
use App\Models\Role;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use App\Models\User;
use App\Models\Visit;
use App\Services\Rewards\RewardEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

# =========================================================================
# What was bought, and what it earned
# =========================================================================

/**
 * A user holding exactly the permissions named, through a role of their own.
 *
 * Self-contained rather than borrowed from another reward test file: these
 * helpers are global functions, Pest gives no guarantee which file declares
 * one first, and two files declaring the same name is a fatal error.
 *
 * @param  array<int, Permission>  $permissions
 */
function purchaseProductStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        SpatiePermission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'purchase-product-tester-'.Str::random(8),
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function purchasePayload(Customer $customer, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $customer->id,
        'amount' => '185000.00',
        'status' => PurchaseStatus::Completed->value,
        'purchased_at' => now()->subHour()->toDateTimeString(),
    ], $overrides);
}

# =========================================================================
# The picker has to be given something to pick from
# =========================================================================

it('hands the new purchase screen the floor to choose from', function () {
    $oven = Product::factory()->create(['name' => 'Gas oven', 'sku' => 'SHF-OVEN-60']);

    $this->actingAs(purchaseProductStaff([Permission::PurchasesCreate]))
        ->get(route('admin.purchases.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchases/Form')
            ->has('products', 1)
            ->where('products.0.value', $oven->id)
            ->where('products.0.label', 'Gas oven')
            ->where('products.0.hint', 'SHF-OVEN-60')
            ->has('selected_products', 0));
});

it('hands the edit screen the floor and the sale it already names', function () {
    $oven = Product::factory()->create(['name' => 'Gas oven']);
    Product::factory()->create(['name' => 'Fridge']);

    $purchase = Purchase::factory()->create();
    $purchase->products()->sync([$oven->id]);

    $this->actingAs(purchaseProductStaff([Permission::PurchasesUpdate]))
        ->get(route('admin.purchases.edit', $purchase))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchases/Form')
            ->has('products', 2)
            ->where('purchase.product_ids', [$oven->id])
            ->where('selected_products.0.label', 'Gas oven'));
});

it('still names a product withdrawn from the floor when the sale is reopened', function () {
    $oven = Product::factory()->create(['name' => 'Gas oven']);

    $purchase = Purchase::factory()->create();
    $purchase->products()->sync([$oven->id]);

    $oven->delete();

    $this->actingAs(purchaseProductStaff([Permission::PurchasesUpdate]))
        ->get(route('admin.purchases.edit', $purchase))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            # Out of the pickable list, but still on this sale: a save that
            # lost the chip would post the sale back short.
            ->has('products', 0)
            ->where('purchase.product_ids', [$oven->id])
            ->where('selected_products.0.value', $oven->id)
            ->where('selected_products.0.label', 'Gas oven'));
});

# =========================================================================
# Writing it down
# =========================================================================

it('records everything the sale named', function () {
    $oven = Product::factory()->create();
    $machine = Product::factory()->create();
    $customer = Customer::factory()->create();

    $this->actingAs(purchaseProductStaff([Permission::PurchasesViewAny, Permission::PurchasesCreate]))
        ->post(route('admin.purchases.store'), purchasePayload($customer, [
            'product_ids' => [$oven->id, $machine->id],
        ]))
        ->assertRedirect(route('admin.purchases.index'))
        ->assertSessionHasNoErrors();

    expect(Purchase::query()->sole()->products->pluck('id')->all())
        ->toEqualCanonicalizing([$oven->id, $machine->id]);
});

it('clears the products when the picker is emptied', function () {
    $oven = Product::factory()->create();

    $purchase = Purchase::factory()->create();
    $purchase->products()->sync([$oven->id]);

    $this->actingAs(purchaseProductStaff([Permission::PurchasesViewAny, Permission::PurchasesUpdate]))
        ->patch(route('admin.purchases.update', $purchase), purchasePayload(
            $purchase->customer,
            ['product_ids' => []],
        ))
        ->assertSessionHasNoErrors();

    expect($purchase->refresh()->products)->toBeEmpty();
    $this->assertDatabaseEmpty('purchase_product');
});

# An absent `product_ids` means "no answer", not "clear them" - the same
# distinction the request draws for `visit_id`.
it('leaves the products and the visit alone when an unrelated field is corrected', function () {
    $oven = Product::factory()->create();
    $customer = Customer::factory()->create();
    $visit = Visit::factory()->create(['customer_id' => $customer->id]);

    $purchase = Purchase::factory()->create([
        'customer_id' => $customer->id,
        'visit_id' => $visit->id,
        'reference' => 'INV-0001',
    ]);
    $purchase->products()->sync([$oven->id]);

    $this->actingAs(purchaseProductStaff([Permission::PurchasesViewAny, Permission::PurchasesUpdate]))
        ->patch(route('admin.purchases.update', $purchase), purchasePayload(
            $customer,
            ['reference' => 'INV-0002'],
        ))
        ->assertSessionHasNoErrors();

    $purchase->refresh();

    expect($purchase->reference)->toBe('INV-0002')
        ->and($purchase->visit_id)->toBe($visit->id)
        ->and($purchase->products->pluck('id')->all())->toBe([$oven->id]);
});

it('refuses a product that is not on the floor', function () {
    $withdrawn = Product::factory()->create();
    $withdrawn->delete();

    $this->actingAs(purchaseProductStaff([Permission::PurchasesViewAny, Permission::PurchasesCreate]))
        ->post(route('admin.purchases.store'), purchasePayload(
            Customer::factory()->create(),
            ['product_ids' => [$withdrawn->id]],
        ))
        ->assertSessionHasErrors('product_ids.0');
});

# =========================================================================
# Reading it back
# =========================================================================

it('names what was bought on every row of the purchases list', function () {
    $oven = Product::factory()->create(['name' => 'Gas oven']);
    $machine = Product::factory()->create(['name' => 'Coffee machine']);

    $purchase = Purchase::factory()->create();
    $purchase->products()->sync([$oven->id, $machine->id]);

    # A sale that named nothing: must read as an empty list, not a missing one.
    Purchase::factory()->create(['purchased_at' => now()->subYear()]);

    $this->actingAs(purchaseProductStaff([Permission::PurchasesViewAny]))
        ->get(route('admin.purchases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchases/Index')
            ->has('purchases.data', 2)
            ->where('purchases.data.0.product_names', ['Gas oven', 'Coffee machine'])
            ->where('purchases.data.1.product_names', []));
});

it('says a sale that named nothing cannot reach a paired reward', function () {
    $oven = Product::factory()->create();

    campaignPairing(['Baking tray set' => [5, $oven]]);

    $purchase = Purchase::factory()->create(['purchased_at' => now()->subHour()]);

    expect(app(RewardEligibilityService::class)->refusalFor($purchase))
        ->toBe('The rewards left in this campaign are all paired to a product, and nothing was recorded as bought on this purchase.');
});

# =========================================================================
# Why this customer won this
# =========================================================================

/**
 * @param  array<int, Product>  $bought
 */
function winFromPurchase(
    RewardCampaign $campaign,
    CampaignReward $reward,
    array $bought,
    string $code,
    string $reference,
): ShuffleResult {
    $customer = Customer::factory()->create();

    $purchase = Purchase::factory()->create([
        'customer_id' => $customer->id,
        'reference' => $reference,
        'purchased_at' => CarbonImmutable::parse('2026-08-19 09:00'),
    ]);
    $purchase->products()->sync(array_map(fn (Product $product) => $product->id, $bought));

    $session = ShuffleSession::factory()->shuffled()->create([
        'campaign_id' => $campaign->id,
        'customer_id' => $customer->id,
        'purchase_id' => $purchase->id,
    ]);

    $entry = RewardPoolEntry::factory()->claimed()->create([
        'campaign_id' => $campaign->id,
        'campaign_reward_id' => $reward->id,
    ]);

    return ShuffleResult::factory()->create([
        'shuffle_session_id' => $session->id,
        'reward_pool_entry_id' => $entry->id,
        'code' => $code,
        'won_at' => CarbonImmutable::parse('2026-08-20 10:00'),
        'status' => RewardResultStatus::Unredeemed,
    ]);
}

it('says on the winners list which purchase earned the reward and why', function () {
    $oven = Product::factory()->create(['name' => 'Gas oven']);
    $machine = Product::factory()->create(['name' => 'Coffee machine']);

    $campaign = RewardCampaign::factory()->active()->create();
    $trays = CampaignReward::factory()
        ->qualifyingFor($oven)
        ->create(['campaign_id' => $campaign->id]);

    winFromPurchase($campaign, $trays, [$oven, $machine], 'SHF-PAIR01', 'INV-7781');

    $this->actingAs(purchaseProductStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/rewards/Winners')
            ->has('rewards.data', 1)
            ->where('rewards.data.0.purchase_reference', 'INV-7781')
            ->where('rewards.data.0.purchased_on', '19 Aug 2026')
            # The oven and not the coffee machine: the reason, not the receipt.
            ->where('rewards.data.0.qualifying_products', ['Gas oven']));
});

it('explains nothing on the winners list when the reward was open to anybody', function () {
    $oven = Product::factory()->create(['name' => 'Gas oven']);

    $campaign = RewardCampaign::factory()->active()->create();
    $audit = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);

    winFromPurchase($campaign, $audit, [$oven], 'SHF-OPEN01', 'INV-7782');

    $this->actingAs(purchaseProductStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index'))
        ->assertInertia(fn ($page) => $page
            ->where('rewards.data.0.qualifying_products', [])
            ->where('rewards.data.0.purchase_reference', 'INV-7782'));
});

it('says nothing about a purchase on a turn that never had one', function () {
    $campaign = RewardCampaign::factory()->active()->create();
    $reward = CampaignReward::factory()->create(['campaign_id' => $campaign->id]);

    $entry = RewardPoolEntry::factory()->claimed()->create([
        'campaign_id' => $campaign->id,
        'campaign_reward_id' => $reward->id,
    ]);

    ShuffleResult::factory()->create([
        'shuffle_session_id' => ShuffleSession::factory()->shuffled()->create([
            'campaign_id' => $campaign->id,
            'purchase_id' => null,
        ])->id,
        'reward_pool_entry_id' => $entry->id,
        'code' => 'SHF-STAFF1',
    ]);

    $this->actingAs(purchaseProductStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.winners.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rewards.data.0.purchase_reference', null)
            ->where('rewards.data.0.purchased_on', null)
            ->where('rewards.data.0.qualifying_products', []));
});
