<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Str;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Every capability the application checks for.
 *
 * Declared here rather than in the database - a permission row with no case behind
 * it is a promise nothing keeps. `permissions:sync` reconciles this enum into the
 * spatie tables. The value reads `group.action`, and the group is what the
 * permission matrix groups by.
 */
#[TypeScript]
enum Permission: string
{
    # =========================================================================
    # Dashboard
    # =========================================================================

    case DashboardView = 'dashboard.view';

    # =========================================================================
    # Visits
    # =========================================================================

    case VisitsViewAny = 'visits.view.any';
    case VisitsViewOwn = 'visits.view.own';
    case VisitsCreate = 'visits.create';
    case VisitsUpdate = 'visits.update';
    case VisitsDelete = 'visits.delete';
    case VisitsExport = 'visits.export';

    # =========================================================================
    # Customers
    # =========================================================================

    case CustomersViewAny = 'customers.view.any';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';
    case CustomersExport = 'customers.export';
    case CustomersImport = 'customers.import';

    # =========================================================================
    # Products
    # =========================================================================

    case ProductsViewAny = 'products.view.any';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDelete = 'products.delete';

    # =========================================================================
    # Purchases
    # =========================================================================

    case PurchasesViewAny = 'purchases.view.any';
    case PurchasesCreate = 'purchases.create';
    case PurchasesUpdate = 'purchases.update';
    case PurchasesDelete = 'purchases.delete';

    # =========================================================================
    # Reward shuffle
    # =========================================================================

    case RewardsView = 'rewards.view';

    case RewardsCampaignsCreate = 'rewards.campaigns.create';
    case RewardsCampaignsUpdate = 'rewards.campaigns.update';
    case RewardsCampaignsDelete = 'rewards.campaigns.delete';

    # -------------------------------------------------------------------------
    # Writing the catalogue is a standing trust, apart from running a campaign
    # out of it. Reading it rides on `rewards.view`.
    # -------------------------------------------------------------------------
    case RewardsCatalogueCreate = 'rewards.catalogue.create';
    case RewardsCatalogueUpdate = 'rewards.catalogue.update';
    case RewardsCatalogueDelete = 'rewards.catalogue.delete';

    # The customer's own shuffle needs no permission - it answers to the token
    # in the QR code instead.
    case RewardsShuffle = 'rewards.shuffle';

    case RewardsRedeem = 'rewards.redeem';

    # =========================================================================
    # Administration
    # =========================================================================

    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';
    case RolesAssign = 'roles.assign';

    case UsersViewAny = 'users.view.any';
    case UsersCreate = 'users.create';

    # Setting somebody's password is the same trust as owning their login, so it
    # sits under this rather than earning a permission of its own.
    case UsersUpdate = 'users.update';

    # The one grant that does not show up on the Roles screen: whoever holds this
    # can leave an ability attached to an account after the role behind it is
    # taken away. Handed out on purpose, not as a side effect of `users.update`.
    case UsersPermissions = 'users.permissions';

    # =========================================================================
    # Your own account
    # =========================================================================

    # Off by default, and granted by no role. The address is where a password
    # reset lands, so an account that can move its own is an account that can be
    # taken over; the normal door is the Users screen, behind `users.update`.
    case ProfileEmailUpdate = 'profile.email.update';

    /**
     * The domain this permission belongs to, used to group the permission matrix.
     */
    public function group(): string
    {
        return Str::before($this->value, '.');
    }

    public function label(): string
    {
        return Str::of($this->value)
            ->after('.')
            ->replace(['.', '_'], ' ')
            ->ucfirst()
            ->toString();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Every permission keyed by group, in declaration order.
     *
     * @return array<string, array<int, self>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $permission) {
            $grouped[$permission->group()][] = $permission;
        }

        return $grouped;
    }

    public static function groupLabel(string $group): string
    {
        return Str::of($group)->replace('_', ' ')->headline()->toString();
    }
}
