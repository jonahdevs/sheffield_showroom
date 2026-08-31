declare namespace App {
    namespace Data {
        export type CampaignRewardData = {
            id: number;
            reward_id: number;
            name: string;
            description: string | null;
            type: App.Enums.RewardType;
            type_label: string;
            product_id: number | null;
            product_name: string | null;
            value: string | null;
            value_unit: App.Enums.RewardValueUnit | null;
            value_label: string | null;
            loaded: number;
            available: number;
            claimed: number;
            void: number;
            validity_days: number | null;
            terms: string | null;
            is_active: boolean;
            qualifying_products: {
                id: number;
                name: string;
            }[];
        };
        export type CustomerFormData = {
            id: number;
            type: App.Enums.CustomerType;
            name: string | null;
            phone: string;
            email: string | null;
            id_number: string | null;
            company_name: string | null;
            industry: string | null;
            country: string;
            state: string | null;
            city: string | null;
            street_address: string | null;
            area: string | null;
            postal_code: string | null;
            notes: string | null;
            display_name: string;
        };
        export type CustomerOptionData = {
            value: number;
            label: string;
            hint: string | null;
            image_url: string | null;
            keywords: string | null;
            type: App.Enums.CustomerType;
            name: string | null;
            company_name: string | null;
            industry: string | null;
            email: string | null;
            id_number: string | null;
        };
        export type CustomerRowData = {
            id: number;
            type: App.Enums.CustomerType;
            type_label: string;
            display_name: string;
            name: string | null;
            company_name: string | null;
            phone: string;
            email: string | null;
            visits_count: number;
            last_visit: string | null;
        };
        export type DashboardProductInterestData = {
            id: number;
            name: string;
            image_url: string | null;
            visits: number;
        };
        export type DashboardRangeData = {
            preset: string;
            from: string;
            to: string;
            label: string;
            days: number;
        };
        export type DashboardRespondentData = {
            name: string;
            visits: number;
            customers: number;
            follow_ups: number;
        };
        export type DashboardSliceData = {
            value: string;
            label: string;
            count: number;
            share: number;
        };
        export type DashboardStatData = {
            key: string;
            label: string;
            value: number;
            previous: number;
            change: number | null;
        };
        export type DashboardTrendPointData = {
            date: string;
            label: string;
            visits: number;
        };
        export type OptionData = {
            value: number;
            label: string;
            hint: string | null;
            image_url: string | null;
        };
        export type PermissionGroupData = {
            group: string;
            group_label: string;
            permissions: App.Data.PermissionOptionData[];
        };
        export type PermissionOptionData = {
            value: string;
            label: string;
            grantable: boolean;
        };
        export type PermissionRowData = {
            value: string;
            label: string;
            group: string;
            group_label: string;
            roles: string[];
            users: string[];
        };
        export type ProductData = {
            id: number;
            name: string;
            sku: string | null;
            model_number: string | null;
            image_url: string | null;
            status: App.Enums.ProductStatus;
            status_label: string;
            source: App.Enums.ProductSource;
            is_synced: boolean;
            added: string;
        };
        export type ProductOptionData = {
            value: number;
            label: string;
            hint: string | null;
            image_url: string | null;
            model_number: string | null;
            quantity: number;
            interest_level: App.Enums.InterestLevel | null;
        };
        export type PurchaseRowData = {
            id: number;
            customer_name: string;
            reference: string | null;
            amount: string;
            status: App.Enums.PurchaseStatus;
            status_label: string;
            purchased_on: string;
            shuffle_id: number | null;
            shuffle_status: string | null;
            refusal: string | null;
            can_delete: boolean;
        };
        export type RewardCampaignData = {
            id: number;
            name: string;
            description: string | null;
            status: App.Enums.CampaignStatus;
            status_label: string;
            is_published: boolean;
            is_running: boolean;
            starts_at: string | null;
            ends_at: string | null;
            max_shuffles_per_customer: number;
            minimum_purchase_amount: string | null;
            loaded: number;
            available: number;
            claimed: number;
            void: number;
            turns_given: number;
            rewards: App.Data.CampaignRewardData[];
        };
        export type RewardWinnerRowData = {
            id: number;
            code: string;
            customer_name: string;
            customer_type: App.Enums.CustomerType;
            customer_company: string | null;
            customer_phone: string | null;
            campaign_name: string;
            reward_name: string;
            type: App.Enums.RewardType;
            type_label: string;
            value: string | null;
            won_on: string;
            expires_on: string | null;
            status: App.Enums.RewardResultStatus;
            status_label: string;
            redeemed_on: string | null;
            redeemed_by: string | null;
        };
        export type RoleData = {
            id: number;
            name: string;
            label: string;
            description: string | null;
            is_system: boolean;
            holders: number;
            permissions: string[];
            first_holder_name: string | null;
        };
        export type RoleHolderData = {
            id: number;
            name: string;
            email: string;
            roles: string[];
            is_self: boolean;
            direct_permissions: number;
            is_manageable: boolean;
        };
        export type ShuffleCampaignData = {
            name: string;
            description: string | null;
            runs_from: string | null;
            runs_to: string | null;
            minimum_purchase: string | null;
            shuffles_per_customer: number;
            terms: string[];
        };
        export type ShuffleRewardData = {
            code: string;
            name: string;
            description: string | null;
            type: App.Enums.RewardType;
            type_label: string;
            value: string | null;
            terms: string | null;
            status: App.Enums.RewardResultStatus;
            status_label: string;
            won_on: string;
            expires_on: string | null;
            is_redeemable: boolean;
            customer_name: string | null;
            redeemed_on: string | null;
            redeemed_by: string | null;
        };
        export type ShuffleSessionData = {
            id: number;
            customer_name: string;
            status: App.Enums.ShuffleSessionStatus;
            status_label: string;
            url: string;
            expires_at: string | null;
            is_shuffleable: boolean;
            reward: App.Data.ShuffleRewardData | null;
        };
        export type UserFormData = {
            id: number;
            name: string;
            email: string;
            roles: string[];
            permissions: string[];
            is_self: boolean;
        };
        export type VisitFormData = {
            id: number;
            customer_id: number;
            customer_type: App.Enums.CustomerType;
            customer_name: string | null;
            phone: string;
            email: string | null;
            id_number: string | null;
            company_name: string | null;
            industry: string | null;
            visited_on: string;
            visited_time: string;
            purpose: App.Enums.VisitPurpose;
            source: App.Enums.CustomerSource;
            respondent: string | null;
            expected_follow_up_on: string | null;
            notes: string | null;
            products: App.Data.ProductOptionData[];
            customer_label: string;
        };
        export type VisitRowData = {
            id: number;
            customer_name: string;
            customer_type: App.Enums.CustomerType;
            customer_company: string | null;
            customer_phone: string | null;
            purpose: App.Enums.VisitPurpose;
            purpose_label: string;
            visited_on: string;
            visited_time: string;
            products: string[];
            attended_by: string | null;
            has_notes: boolean;
        };
    }
    namespace Enums {
        export type CampaignStatus =
            | 'draft'
            | 'scheduled'
            | 'active'
            | 'paused'
            | 'completed'
            | 'cancelled';
        export type CustomerSource =
            | 'walk_in'
            | 'referral'
            | 'website'
            | 'social_media'
            | 'exhibition'
            | 'repeat'
            | 'advertisement'
            | 'sales_call'
            | 'other';
        export type CustomerType = 'individual' | 'company';
        export type InterestLevel = 'high' | 'medium' | 'low';
        export type Permission =
            | 'dashboard.view'
            | 'visits.view.any'
            | 'visits.view.own'
            | 'visits.create'
            | 'visits.update'
            | 'visits.delete'
            | 'visits.export'
            | 'customers.view.any'
            | 'customers.create'
            | 'customers.update'
            | 'customers.delete'
            | 'customers.export'
            | 'customers.import'
            | 'products.view.any'
            | 'products.create'
            | 'products.update'
            | 'products.delete'
            | 'purchases.view.any'
            | 'purchases.create'
            | 'purchases.update'
            | 'purchases.delete'
            | 'rewards.view'
            | 'rewards.campaigns.create'
            | 'rewards.campaigns.update'
            | 'rewards.campaigns.delete'
            | 'rewards.shuffle'
            | 'rewards.redeem'
            | 'roles.view'
            | 'roles.create'
            | 'roles.update'
            | 'roles.delete'
            | 'roles.assign'
            | 'users.view.any'
            | 'users.create'
            | 'users.update'
            | 'users.permissions'
            | 'profile.email.update';
        export type PoolEntryStatus = 'available' | 'claimed' | 'void';
        export type ProductSource = 'manual' | 'website';
        export type ProductStatus =
            'draft' | 'published' | 'inactive' | 'archived';
        export type PurchaseStatus = 'pending' | 'completed' | 'cancelled';
        export type RewardResultStatus =
            'unredeemed' | 'redeemed' | 'expired' | 'cancelled';
        export type RewardType =
            | 'discount'
            | 'product'
            | 'drawing_layout'
            | 'kitchen_audit'
            | 'complimentary_service'
            | 'installation';
        export type RewardValueUnit = 'percentage' | 'currency';
        export type ShuffleSessionStatus =
            'pending' | 'shuffled' | 'expired' | 'cancelled';
        export type VisitPurpose =
            | 'new_enquiry'
            | 'quotation'
            | 'product_viewing'
            | 'follow_up'
            | 'order'
            | 'after_sales'
            | 'complaint'
            | 'collection'
            | 'other';
        export type VisitReport = 'full' | 'reception';
    }
}
