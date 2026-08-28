declare namespace App {
    namespace Data {
        export type CustomerFormData = {
            id: number;
            type: App.Enums.CustomerType;
            name: string | null;
            date_of_birth: string | null;
            occupation: string | null;
            company_name: string | null;
            industry: string | null;
            contact_person: string | null;
            contact_person_position: string | null;
            phone: string;
            alternative_phone: string | null;
            email: string | null;
            address_line_1: string | null;
            address_line_2: string | null;
            city: string | null;
            state: string | null;
            postal_code: string | null;
            country: string;
            notes: string | null;
            display_name: string;
        };
        export type CustomerOptionData = {
            value: number;
            label: string;
            hint: string | null;
            image_url: string | null;
            type: App.Enums.CustomerType;
            name: string | null;
            company_name: string | null;
            email: string | null;
        };
        export type CustomerRowData = {
            id: number;
            type: App.Enums.CustomerType;
            type_label: string;
            display_name: string;
            subtitle: string | null;
            phone: string;
            email: string | null;
            location: string;
            added: string;
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
            image_url: string | null;
            source: App.Enums.ProductSource;
            is_synced: boolean;
            added: string;
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
            company_name: string | null;
            phone: string;
            email: string | null;
            visited_on: string;
            visited_time: string;
            purpose: App.Enums.VisitPurpose;
            source: App.Enums.CustomerSource;
            respondent: string | null;
            expected_follow_up_on: string | null;
            duration_minutes: number | null;
            notes: string | null;
            product_ids: number[];
            products: App.Data.OptionData[];
            customer_label: string;
        };
        export type VisitRowData = {
            id: number;
            customer_name: string;
            customer_phone: string | null;
            purpose: App.Enums.VisitPurpose;
            purpose_label: string;
            source: App.Enums.CustomerSource;
            source_label: string;
            visited_on: string;
            visited_time: string;
            duration: string | null;
            product_count: number;
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
