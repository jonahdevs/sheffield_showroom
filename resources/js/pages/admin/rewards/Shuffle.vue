<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Ban, Dices, RefreshCw, Share2, Ticket } from '@lucide/vue';
import QRCode from 'qrcode';
import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AlertError from '@/components/AlertError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { confirmDelete } from '@/lib/confirm';
import { dashboard } from '@/routes';
import { index as rewardsIndex } from '@/routes/admin/rewards';
import { destroy, grant } from '@/routes/admin/shuffles';

const props = defineProps<{
    session: App.Data.ShuffleSessionData;
    campaign_name: string;
    can: { run: boolean; cancel: boolean; grant: boolean };
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

/* The page draws no field errors of its own, so a refusal - a closed campaign,
   an empty drawer, a turn the customer is still holding - is caught here and
   shown rather than landing nowhere. */
const refusal = ref<string | null>(null);

function report(bag: Record<string, string>) {
    refusal.value = bag.shuffle ?? null;
}

async function cancelShuffle() {
    if (
        !(await confirmDelete(
            'The customer loses this turn, and the sale behind it cannot earn another.',
        ))
    ) {
        return;
    }

    refusal.value = null;

    router.delete(destroy(props.session.id).url, {
        preserveScroll: true,
        onError: report,
    });
}

/*
  A turn nobody earned. It steps over the campaign's per-customer limit on
  purpose - see `ShuffleSessionService::grantAfter` - so it is confirmed rather
  than given on one press, and the wording says what it cannot do: a granted
  turn carries no purchase, so the rewards paired to a product are out of it.
*/
async function grantAnother() {
    if (
        !(await confirmDelete(
            `${props.session.customer_name} gets a fresh turn, on top of whatever this campaign allows each customer. It carries no purchase, so it can only win a reward that is not paired to a product.`,
            'Yes, give another turn!',
        ))
    ) {
        return;
    }

    refusal.value = null;

    router.post(grant(props.session.id).url, {}, { onError: report });
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

        <AlertError
            v-if="refusal"
            :errors="[refusal]"
            title="That was refused."
            data-test="shuffle-refusal"
        />

        <!-- One row for the life of the turn: which buttons stand in it is what
             changes. A spent or lapsed turn keeps only the way out and, for
             whoever holds `rewards.shuffle.grant`, another go. -->
        <div class="flex flex-wrap items-center justify-end gap-3">
            <!-- Icon only, but standing as tall as the labelled buttons beside
                 it. `size="icon"` is a 36px square and the default size is
                 padding plus a line of text, which an icon alone does not
                 make - so the empty `::before` supplies that one line box
                 (`1lh`) and `gap-0` keeps it from spacing the icon along. -->
            <Button
                v-if="props.session.is_shuffleable"
                variant="outline"
                class="gap-0 before:h-[1lh] before:content-['']"
                aria-label="Share this shuffle with the customer"
                title="Share this shuffle with the customer"
                data-test="share-shuffle"
                @click="shareLink"
            >
                <Share2 />
            </Button>

            <!-- `ShuffleSessionPolicy::cancel` already refuses a turn that is not
                 pending, so this needs no second condition. -->
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
                 of that experience here. Opened in a new tab so this screen -
                 the QR code, the code to read out, the Cancel button - is
                 still behind it when the turn is over. -->
            <Button
                v-if="props.can.run && props.session.is_shuffleable"
                as-child
                variant="outline"
                data-test="run-shuffle"
            >
                <a :href="props.session.url" target="_blank" rel="noopener">
                    <Dices />
                    Shuffle on this screen
                </a>
            </Button>

            <!-- `ShuffleSessionPolicy::grant` holds this back until the turn is
                 over, so it never stands beside a live QR code. -->
            <Button
                v-if="props.can.grant"
                data-test="grant-shuffle"
                @click="grantAnother"
            >
                <Ticket />
                Give another chance
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
