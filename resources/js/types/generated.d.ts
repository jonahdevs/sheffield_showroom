declare namespace App {
    namespace Data {
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
            duration_minutes: number | null;
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
            duration: string | null;
            products: string[];
            attended_by: string | null;
            has_notes: boolean;
        };
    }
    namespace Enums {
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
            | 'roles.view'
            | 'roles.create'
            | 'roles.update'
            | 'roles.delete'
            | 'roles.assign'
            | 'users.view.any'
            | 'users.create'
            | 'users.update';
        export type ProductSource = 'manual' | 'website';
        export type ProductStatus =
            'draft' | 'published' | 'inactive' | 'archived';
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
    }
}
