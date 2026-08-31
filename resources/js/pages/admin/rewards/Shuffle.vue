<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Ban, Dices, RefreshCw } from '@lucide/vue';
import QRCode from 'qrcode';
import { onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { confirmDelete } from '@/lib/confirm';
import { dashboard } from '@/routes';
import { index as rewardsIndex } from '@/routes/admin/rewards';
import { destroy } from '@/routes/admin/shuffles';

const props = defineProps<{
    session: App.Data.ShuffleSessionData;
    campaign_name: string;
    can: { run: boolean; cancel: boolean };
}>();

/*
 * The code is drawn in the browser from the URL the server sent, rather than
 * rendered server-side and shipped as an image. It means the token crosses the
 * wire exactly once - inside the page props this screen already needs - and
 * nothing has to route, cache or expire a generated image.
 *
 * A canvas rather than an SVG string: this is displayed once, at one size, on
 * a screen somebody points a phone at, and a canvas cannot be dragged out of
 * the page into an email as easily as an inline image can.
 */
const canvas = ref<HTMLCanvasElement | null>(null);
const drawFailed = ref(false);

async function drawCode() {
    if (canvas.value === null) {
        return;
    }

    try {
        await QRCode.toCanvas(canvas.value, props.session.url, {
            width: 320,
            /* One module of quiet zone rather than the default four. The card
               around it already provides the white margin a scanner needs, and
               four leaves the code floating in the middle of a lot of nothing
               on a screen being read from a metre away. */
            margin: 1,
            errorCorrectionLevel: 'M',
            color: { dark: '#000000', light: '#ffffff' },
        });

        drawFailed.value = false;
    } catch {
        /* A phone with the URL typed in still works, so a failed draw is a
           degraded screen rather than a dead one. */
        drawFailed.value = true;
    }
}

onMounted(drawCode);

/* Redrawn when the turn is used: the code comes off the screen entirely, and
   the canvas it was on is gone with it. */
watch(() => props.session.is_shuffleable, drawCode);

async function cancelShuffle() {
    if (
        !(await confirmDelete(
            'The customer loses this turn, and the sale behind it cannot earn another.',
        ))
    ) {
        return;
    }

    router.delete(destroy(props.session.id).url, { preserveScroll: true });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Rewards', href: rewardsIndex().url },
            { title: 'Shuffle' },
        ],
    },
});
</script>

<!--
  The screen a customer points a phone at.

  Deliberately sparse. It is read across a counter, at arm's length, by
  somebody who is being handed a phone and told to scan - so there is one
  thing on it worth looking at, and everything else is small.
-->
<template>
    <Head :title="`Shuffle for ${props.session.customer_name}`" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5">
        <div>
            <h1 class="text-2xl leading-tight">
                {{ props.session.customer_name }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ props.campaign_name }}
            </p>
        </div>

        <Card as="section" class="gap-0 p-0">
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-divider px-5 py-3.5"
            >
                <h2
                    class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                >
                    Scan to shuffle
                </h2>
                <Badge
                    :variant="
                        props.session.is_shuffleable ? 'secondary' : 'outline'
                    "
                    data-test="session-status"
                >
                    {{ props.session.status_label }}
                </Badge>
            </div>

            <!--
              The code, while there is still a turn behind it. Once the reward
              is won the QR is worth nothing and showing it would invite
              somebody to scan a page that only says "already claimed".
            -->
            <div
                v-if="props.session.is_shuffleable"
                class="flex flex-col items-center gap-4 p-6"
            >
                <div class="rounded-xl bg-white p-4 ring-1 ring-border">
                    <canvas
                        ref="canvas"
                        class="block size-[320px] max-w-full"
                        role="img"
                        :aria-label="`QR code linking to the shuffle for ${props.session.customer_name}`"
                        data-test="qr-canvas"
                    />
                </div>

                <!-- The address in words as well, for the phone that will not
                     scan and the one whose camera app is being uncooperative. -->
                <p
                    class="max-w-full text-center font-mono text-xs break-all text-muted-foreground"
                    data-test="shuffle-url"
                >
                    {{ props.session.url }}
                </p>

                <p
                    v-if="drawFailed"
                    class="text-xs text-destructive"
                    data-test="qr-failed"
                >
                    The code could not be drawn. Read the address above out
                    instead.
                </p>

                <p
                    v-if="props.session.expires_at"
                    class="text-xs text-faint"
                    data-test="expires-at"
                >
                    Valid until {{ props.session.expires_at }}
                </p>
            </div>

            <!--
              What was won, once it has been. The same code the customer is
              looking at on their own phone, so staff can read it back to them
              if the screenshot did not save.
            -->
            <div
                v-else-if="props.session.reward"
                class="flex flex-col gap-3 p-6"
                data-test="reward-won"
            >
                <span class="text-xs text-muted-foreground">They won</span>
                <span class="text-xl font-bold">
                    {{ props.session.reward.name }}
                    <span
                        v-if="props.session.reward.value"
                        class="text-primary"
                    >
                        &mdash; {{ props.session.reward.value }}
                    </span>
                </span>
                <span
                    class="w-fit rounded-lg bg-muted px-3 py-2 font-mono text-lg font-bold tracking-widest"
                    data-test="reward-code"
                >
                    {{ props.session.reward.code }}
                </span>
                <span class="text-xs text-muted-foreground">
                    Won {{ props.session.reward.won_on
                    }}<template v-if="props.session.reward.expires_on">
                        &middot; expires
                        {{ props.session.reward.expires_on }}</template
                    >
                </span>
            </div>

            <div v-else class="p-6" data-test="turn-closed">
                <p class="rounded-lg bg-muted/50 p-4 text-sm">
                    This turn is {{ props.session.status_label.toLowerCase() }},
                    and nothing was won on it.
                </p>
            </div>
        </Card>

        <div
            v-if="props.session.is_shuffleable"
            class="flex flex-wrap items-center justify-end gap-3"
        >
            <Button
                v-if="props.can.cancel"
                variant="quiet"
                data-test="cancel-shuffle"
                @click="cancelShuffle"
            >
                <Ban />
                Cancel this turn
            </Button>

            <!--
              The fallback, for the customer whose phone will not scan or who
              does not have one on them.

              It goes to the customer's own page rather than drawing a table
              here: that page is the experience, and a second copy of it on the
              admin side would be the one that quietly drifted - and the one
              standing in front of the customer when it did. Staff turn the
              screen round; what the customer sees is exactly what they would
              have seen in their hand.
            -->
            <Button
                v-if="props.can.run"
                as-child
                variant="outline"
                data-test="run-shuffle"
            >
                <a :href="props.session.url">
                    <Dices />
                    Shuffle on this screen
                </a>
            </Button>

            <Button as-child variant="quiet">
                <Link :href="rewardsIndex().url">
                    <RefreshCw />
                    Back to campaigns
                </Link>
            </Button>
        </div>
    </div>
</template>
