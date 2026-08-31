import { Gift } from '@lucide/vue';
import type { Component } from 'vue';
import AuditIcon from '@/components/icons/AuditIcon.vue';
import DesignIcon from '@/components/icons/DesignIcon.vue';
import DiscountIcon from '@/components/icons/DiscountIcon.vue';
import InstallationIcon from '@/components/icons/InstallationIcon.vue';
import ServiceIcon from '@/components/icons/ServiceIcon.vue';

/**
 * A glyph for each kind of reward.
 *
 * Held here rather than inside the card table, because two things now draw a
 * reward: the cards themselves, and the panel beside them listing what is on
 * offer. A second copy of this map would be the one that fell behind when a
 * sixth reward type arrived.
 *
 * Kept on the client rather than sent from the server for the same reason the
 * visits screen keeps its own: none of it is data. It is how the interface
 * chooses to draw five strings, and a controller has no business holding an
 * icon.
 *
 * All five are drawn artwork rather than a general-purpose icon set - a reward
 * card wants a mark somebody recognises across a counter.
 */
const ICONS: Record<string, Component> = {
    discount: DiscountIcon,
    drawing_layout: DesignIcon,
    kitchen_audit: AuditIcon,
    complimentary_service: ServiceIcon,
    installation: InstallationIcon,
};

/**
 * The glyph for a reward type.
 *
 * Falls back to a gift, so a type added to the enum draws something rather
 * than nothing while somebody decides what it should look like.
 */
export function rewardIcon(type: string): Component {
    return ICONS[type] ?? Gift;
}
