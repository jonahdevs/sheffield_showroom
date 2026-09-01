<?php

use App\Enums\Permission;
use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\CampaignReward;
use App\Models\Product;
use App\Models\Reward;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A user holding exactly the permissions named, through a role of their own.
 *
 * Self-contained rather than calling `rewardsStaff` next door: these helpers
 * are global functions, Pest gives no guarantee which file declares one
 * first, and two files declaring the same name is a fatal error.
 *
 * @param  array<int, Permission>  $permissions
 */
function catalogueStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        SpatiePermission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'catalogue-tester-'.Str::random(8),
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

# =========================================================================
# Who may reach what
# =========================================================================

it('keeps the catalogue behind rewards.view', function () {
    $this->actingAs(catalogueStaff([Permission::PurchasesViewAny]))
        ->get(route('admin.rewards.catalogue.index'))
        ->assertForbidden();
});

it('opens the catalogue to anybody who can read the campaigns', function () {
    $reward = Reward::factory()->discount(10)->create();
    CampaignReward::factory()->forReward($reward)->create();

    $this->actingAs(catalogueStaff([Permission::RewardsView]))
        ->get(route('admin.rewards.catalogue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/rewards/catalogue/Index')
            ->where('rewards.data.0.value_label', '10%')
            ->where('rewards.data.0.campaigns_count', 1)
            ->where('rewards.data.0.can_delete', false)
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false));
});

it('refuses to add a reward without rewards.catalogue.create', function () {
    $this->actingAs(catalogueStaff([Permission::RewardsView]))
        ->post(route('admin.rewards.catalogue.store'), [
            'name' => 'Free oven',
            'type' => RewardType::KitchenAudit->value,
        ])
        ->assertForbidden();

    expect(Reward::query()->count())->toBe(0);
});

# =========================================================================
# Writing the catalogue
# =========================================================================

it('adds a reward and records who wrote it', function () {
    $actor = catalogueStaff([Permission::RewardsView, Permission::RewardsCatalogueCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.catalogue.store'), [
            'name' => '10% off a fitted kitchen',
            'description' => 'Off the quoted price, before delivery.',
            'type' => RewardType::Discount->value,
            'value' => '10.00',
            'value_unit' => RewardValueUnit::Percentage->value,
            'terms' => 'One per household.',
            'default_validity_days' => 60,
            'is_active' => true,
        ])
        ->assertRedirect(route('admin.rewards.catalogue.index'))
        ->assertSessionHasNoErrors();

    $reward = Reward::query()->sole();

    expect($reward->name)->toBe('10% off a fitted kitchen')
        ->and($reward->type)->toBe(RewardType::Discount)
        ->and($reward->value_unit)->toBe(RewardValueUnit::Percentage)
        ->and($reward->readableValue())->toBe('10%')
        ->and($reward->default_validity_days)->toBe(60)
        ->and($reward->created_by)->toBe($actor->id);
});

it('refuses a product reward that names no product', function () {
    $actor = catalogueStaff([Permission::RewardsView, Permission::RewardsCatalogueCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.catalogue.store'), [
            'name' => 'A tray, probably',
            'type' => RewardType::Product->value,
        ])
        ->assertSessionHasErrors('product_id');

    expect(Reward::query()->count())->toBe(0);
});

it('refuses a product on a reward that is not a product', function () {
    $actor = catalogueStaff([Permission::RewardsView, Permission::RewardsCatalogueCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.catalogue.store'), [
            'name' => '10% off',
            'type' => RewardType::Discount->value,
            'product_id' => Product::factory()->create()->id,
        ])
        ->assertSessionHasErrors('product_id');

    expect(Reward::query()->count())->toBe(0);
});

it('refuses a figure with no unit to read it by', function () {
    $actor = catalogueStaff([Permission::RewardsView, Permission::RewardsCatalogueCreate]);

    $this->actingAs($actor)
        ->post(route('admin.rewards.catalogue.store'), [
            'name' => 'Ten of something',
            'type' => RewardType::Discount->value,
            'value' => '10.00',
        ])
        ->assertSessionHasErrors('value_unit');

    expect(Reward::query()->count())->toBe(0);
});

it('saves an edit to a reward', function () {
    $actor = catalogueStaff([Permission::RewardsView, Permission::RewardsCatalogueUpdate]);
    $reward = Reward::factory()->create(['name' => 'Free kitchen audit']);

    $this->actingAs($actor)
        ->patch(route('admin.rewards.catalogue.update', $reward), [
            'name' => 'Free kitchen audit and layout',
            'type' => RewardType::KitchenAudit->value,
            'default_validity_days' => 90,
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($reward->refresh()->name)->toBe('Free kitchen audit and layout')
        ->and($reward->default_validity_days)->toBe(90);
});

# `RewardRequest` prohibits `product_id` for every other kind, so the form
# sends nothing - the controller has to clear it rather than leave it standing.
it('clears the product when a reward stops being one', function () {
    $actor = catalogueStaff([Permission::RewardsView, Permission::RewardsCatalogueUpdate]);
    $oven = Product::factory()->create();
    $reward = Reward::factory()->product($oven)->create();

    $this->actingAs($actor)
        ->patch(route('admin.rewards.catalogue.update', $reward), [
            'name' => '10% off instead',
            'type' => RewardType::Discount->value,
            'value' => '10.00',
            'value_unit' => RewardValueUnit::Percentage->value,
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($reward->refresh()->type)->toBe(RewardType::Discount)
        ->and($reward->product_id)->toBeNull();
});

# =========================================================================
# Deleting, and the retirement that stands in for it
# =========================================================================

it('deletes a reward no campaign has taken', function () {
    $actor = catalogueStaff([Permission::RewardsView, Permission::RewardsCatalogueDelete]);
    $reward = Reward::factory()->create();

    $this->actingAs($actor)
        ->from(route('admin.rewards.catalogue.index'))
        ->delete(route('admin.rewards.catalogue.destroy', $reward))
        ->assertSessionHasNoErrors();

    expect(Reward::query()->whereKey($reward->id)->exists())->toBeFalse();
});

it('will not delete a reward a campaign is holding', function () {
    $actor = catalogueStaff([Permission::RewardsView, Permission::RewardsCatalogueDelete]);
    $reward = Reward::factory()->create();
    CampaignReward::factory()->forReward($reward)->create();

    $this->actingAs($actor)
        ->delete(route('admin.rewards.catalogue.destroy', $reward))
        ->assertForbidden();

    expect(Reward::query()->whereKey($reward->id)->exists())->toBeTrue();
});

it('retires a reward without disturbing the campaigns holding it', function () {
    $actor = catalogueStaff([Permission::RewardsView, Permission::RewardsCatalogueUpdate]);
    $reward = Reward::factory()->create();
    $attachment = CampaignReward::factory()->forReward($reward)->create();

    $this->actingAs($actor)
        ->patch(route('admin.rewards.catalogue.update', $reward), [
            'name' => $reward->name,
            'type' => $reward->type->value,
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($reward->refresh()->is_active)->toBeFalse()
        ->and($attachment->refresh()->is_active)->toBeTrue()
        ->and($attachment->reward_id)->toBe($reward->id);
});
