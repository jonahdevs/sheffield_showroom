<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Ban, Dices, RefreshCw, Share2 } from '@lucide/vue';
import QRCode from 'qrcode';
import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
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
 * Drawn client-side from `session.url`, so the token crosses the wire only in
 * the page props and nothing has to route, cache or expire an image.
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
            /* One module of quiet zone rather than the library's default four:
               the white card around the canvas already gives a scanner the
               margin it needs. */
            margin: 1,
            errorCorrectionLevel: 'M',
            color: { dark: '#000000', light: '#ffffff' },
        });

        drawFailed.value = false;
    } catch {
        drawFailed.value = true;
    }
}

onMounted(drawCode);

/* `v-if` destroys the canvas when the turn closes, so the ref has to be redrawn
   rather than reused if the session becomes shuffleable again. */
watch(() => props.session.is_shuffleable, drawCode);

/*
  The device's own share sheet where there is one, clipboard everywhere else.
  Staff reach for whichever app the customer already uses, so offering a fixed
  list of channels here would be guessing at that.

  A dismissed sheet rejects with `AbortError`; that is somebody changing their
  mind, not a failure, so nothing is said about it.
*/
async function shareLink() {
    const link = props.session.url;

    if (navigator.share) {
        try {
            await navigator.share({
                title: 'Your shuffle',
                text: `${props.session.customer_name}, here is your shuffle for ${props.campaign_name}.`,
                url: link,
            });

            return;
        } catch (error) {
            if ((error as DOMException)?.name === 'AbortError') {
                return;
            }
        }
    }

    /* Refused outside a secure context and in a few browsers. The address is
       printed above and selectable, so a failure here loses nothing. */
    try {
        await navigator.clipboard.writeText(link);
        toast.success('Link copied.');
    } catch {
        toast.error('Copy the address shown above instead.');
    }
}

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
                variant="outline"
                size="icon"
                aria-label="Share this shuffle with the customer"
                data-test="share-shuffle"
                @click="shareLink"
            >
                <Share2 />
            </Button>

            <Button
                v-if="props.can.cancel"
                variant="outline"
                data-test="cancel-shuffle"
                @click="cancelShuffle"
            >
                <Ban />
                Cancel this turn
            </Button>

            <!-- A plain `<a>`, not a `<Link>`: it leaves the admin SPA for the
                 customer's own shuffle page rather than growing a second copy
                 of that experience here. -->
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

            <Button as-child variant="outline">
                <Link :href="rewardsIndex().url">
                    <RefreshCw />
                    Back to campaigns
                </Link>
            </Button>
        </div>
    </div>
</template>
