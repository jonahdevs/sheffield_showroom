import { Gift } from '@lucide/vue';
import type { Component } from 'vue';
import AuditIcon from '@/components/icons/AuditIcon.vue';
import DesignIcon from '@/components/icons/DesignIcon.vue';
import DiscountIcon from '@/components/icons/DiscountIcon.vue';
import InstallationIcon from '@/components/icons/InstallationIcon.vue';
import ServiceIcon from '@/components/icons/ServiceIcon.vue';

/** Keyed by `App.Enums.RewardType`; a new type needs a row here. */
const ICONS: Record<string, Component> = {
    discount: DiscountIcon,
    drawing_layout: DesignIcon,
    kitchen_audit: AuditIcon,
    complimentary_service: ServiceIcon,
    installation: InstallationIcon,
};

/** Falls back to a gift so an unmapped type still draws something. */
export function rewardIcon(type: string): Component {
    return ICONS[type] ?? Gift;
}
