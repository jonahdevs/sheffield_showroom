<script setup lang="ts">
import gsap from 'gsap';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { rewardIcon } from '@/lib/rewardIcons';

/**
 * Card artwork, served from `public` beside the other static images and
 * downscaled on the way in - a card is never drawn near the 1200px sources.
 * The front's gold frame surrounds an empty panel, which is what the inset on
 * the label below keeps the text inside of.
 */
const CARD_FRONT = '/images/shuffle-card-front.jpg';
const CARD_BACK = '/images/shuffle-card-back.jpg';

/** One card face: the kind of thing that could come up, never how many. */
export type ShuffleCard = {
    name: string;
    type: string;
    type_label: string;
    /** Optional: the showroom screen has no offer panel and sends neither. */
    value?: string | null;
    terms?: string | null;
};

const props = defineProps<{
    cards: ShuffleCard[];
    reward: { name: string; type: string } | null;
    playable: boolean;
}>();

const emit = defineEmits<{ pick: [] }>();

/*
 * The tap does not choose the reward. `ShuffleRewardService` chose it on the
 * server, inside a transaction, out of what is left in the pool, and the
 * request is not sent until the tap - so there is nothing on the page to read
 * ahead of it. Whichever card is touched turns over onto that answer, and the
 * unchosen cards are never turned over because there is nothing behind them.
 */
type Phase = 'ready' | 'shuffling' | 'picking' | 'revealing';

const phase = ref<Phase>('ready');
const chosen = ref<number | null>(null);

/**
 * A turn played before this page was loaded. `chosen` is component state, so a
 * refresh forgets which card was tapped and the table would otherwise come
 * back dealt face up under a heading congratulating them. Nothing is worth
 * restoring, so a reload draws the won card alone and no table.
 */
const alreadyWon = computed(
    () => props.reward !== null && chosen.value === null,
);

/** Card index to the slot it currently occupies. */
const slotOf = ref<number[]>(props.cards.map((_, index) => index));

const table = ref<HTMLElement | null>(null);
const cardEls = ref<HTMLElement[]>([]);

/*
 * The turning half has to be its own element: an element at less than full
 * opacity flattens its own 3D context, so the moment the unchosen cards fade
 * back their `backface-visibility` stops being honoured and the face shows
 * through the back, mirrored. Position, scale and opacity live on the button;
 * only the rotation lives in here.
 */
const innerEls = ref<HTMLElement[]>([]);
const burstHost = ref<HTMLElement | null>(null);
const tableWidth = ref(0);

// =========================================================================
// Laying out the table
// =========================================================================

const PER_ROW = 3;
const GAP = 16;
const RATIO = 1.4;

const perRow = computed(() => Math.min(props.cards.length || 1, PER_ROW));
const rows = computed(() =>
    Math.ceil((props.cards.length || 1) / perRow.value),
);

const MIN_CARD = 84;
const MAX_CARD = 240;

/** Three, or one on an already-played turn, which draws a single card. */
const across = computed(() => (alreadyWon.value ? 1 : perRow.value));

const cardWidth = computed(() => {
    const available = tableWidth.value - GAP * (across.value - 1);

    return Math.max(
        MIN_CARD,
        Math.min(MAX_CARD, Math.floor(available / across.value)),
    );
});

const cardHeight = computed(() => Math.round(cardWidth.value * RATIO));

const iconSize = computed(() =>
    Math.max(20, Math.min(64, Math.round(cardWidth.value * 0.28))),
);

const labelSize = computed(() =>
    Math.max(11, Math.min(16, Math.round(cardWidth.value * 0.085))),
);

const tableHeight = computed(
    () => rows.value * cardHeight.value + (rows.value - 1) * GAP,
);

/** Where a slot sits on the table, in pixels from its top left. */
function slotPosition(slot: number): { x: number; y: number } {
    const row = Math.floor(slot / perRow.value);
    const column = slot % perRow.value;

    const inRow = Math.min(
        props.cards.length - row * perRow.value,
        perRow.value,
    );
    const rowWidth = inRow * cardWidth.value + (inRow - 1) * GAP;
    const offset = (tableWidth.value - rowWidth) / 2;

    return {
        x: offset + column * (cardWidth.value + GAP),
        y: row * (cardHeight.value + GAP),
    };
}

/**
 * Deliberately says nothing about rotation: this runs again on every resize,
 * and setting the rotation here too would turn the table face up mid-shuffle.
 */
function place(): void {
    props.cards.forEach((_, index) => {
        const element = cardEls.value[index];

        if (!element) {
            return;
        }

        const { x, y } = slotPosition(slotOf.value[index]);

        gsap.set(element, {
            x,
            y,
            width: cardWidth.value,
            height: cardHeight.value,
        });
    });
}

function measure(): void {
    tableWidth.value = table.value?.clientWidth ?? 0;
    place();
}

let observer: ResizeObserver | null = null;

onMounted(() => {
    measure();

    gsap.set(innerEls.value, { rotationY: 0 });

    observer = new ResizeObserver(measure);

    if (table.value) {
        observer.observe(table.value);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    gsap.killTweensOf([...cardEls.value, ...innerEls.value, ...flying]);
    waiting = null;
});

// =========================================================================
// The shuffle, the pick and the reveal
// =========================================================================

/**
 * The setting, and also a page that is not on screen: a background tab
 * throttles `requestAnimationFrame` - which is what GSAP runs on - to about a
 * frame a second, leaving `shuffleCards()` unresolved and the phase stuck on
 * "Shuffling". A hidden page skips straight to the pick instead.
 */
const reducedMotion = (): boolean =>
    document.hidden ||
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/*
  How long the table is kept moving. Named rather than inline because the two
  are read together: the shuffle runs `SHUFFLE_PASSES * PASS_SECONDS`, and the
  flip that precedes it adds about another 0.6s on a full table.
*/
const SHUFFLE_PASSES = 8;
const PASS_SECONDS = 0.3;

async function shuffleCards(): Promise<void> {
    phase.value = 'shuffling';

    if (reducedMotion()) {
        phase.value = 'picking';

        return;
    }

    await gsap.to(innerEls.value, {
        rotationY: 180,
        duration: 0.35,
        stagger: 0.05,
        ease: 'power2.inOut',
    });

    for (let pass = 0; pass < SHUFFLE_PASSES; pass++) {
        /* A real permutation each pass rather than pairwise swaps: no card can
           sit still through the whole shuffle and be followed. */
        const shuffled = [...slotOf.value];

        for (let i = shuffled.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
        }

        slotOf.value = shuffled;

        await Promise.all(
            props.cards.map((_, index) => {
                const element = cardEls.value[index];

                if (!element) {
                    return Promise.resolve();
                }

                const { x, y } = slotPosition(slotOf.value[index]);

                return gsap
                    .to(element, {
                        x,
                        y,
                        duration: PASS_SECONDS,
                        ease: 'power2.inOut',
                        keyframes: { scale: [1, 1.06, 1], zIndex: [1, 10, 1] },
                    })
                    .then();
            }),
        );
    }

    phase.value = 'picking';
}

function begin(): void {
    if (phase.value !== 'ready' || !props.playable) {
        return;
    }

    void shuffleCards();
}

/**
 * The half turn that starts the moment a card is touched, held so the reveal
 * below can await it rather than fight it.
 */
let edgeOn: gsap.core.Tween | null = null;

function pick(index: number): void {
    if (phase.value !== 'picking') {
        return;
    }

    chosen.value = index;
    phase.value = 'revealing';

    const element = cardEls.value[index];
    const inner = innerEls.value[index];

    emit('pick');

    if (reducedMotion()) {
        return;
    }

    if (element) {
        gsap.to(element, { scale: 1.08, zIndex: 20, duration: 0.25 });
        gsap.to(
            cardEls.value.filter((_, other) => other !== index),
            { opacity: 0.25, scale: 0.94, duration: 0.3 },
        );
    }

    /*
     * The card starts turning on the tap rather than on the answer, and stops
     * at 270 degrees: edge-on, where a card is a line and discloses nothing.
     * The round trip then happens behind a motion already underway.
     */
    if (inner) {
        edgeOn = gsap.to(inner, {
            rotationY: 270,
            duration: 0.3,
            ease: 'power2.in',
            onComplete: () => holdWhileClaiming(element),
        });
    }
}

/**
 * The claim is a round trip and the card is edge-on the whole time it is in
 * the air - a line that does not move reads as a stall, which is what makes a
 * fast request feel like waiting on a server. So the card breathes until the
 * answer lands, on `scale` rather than the rotation, which is spoken for.
 */
let waiting: gsap.core.Tween | null = null;

function holdWhileClaiming(element: HTMLElement | undefined): void {
    if (!element || props.reward !== null) {
        return;
    }

    waiting = gsap.to(element, {
        scale: 1.16,
        duration: 0.5,
        yoyo: true,
        repeat: -1,
        ease: 'sine.inOut',
    });
}

/**
 * The rest of the turn. Driven by the reward arriving rather than by the
 * request finishing, because a refusal - a double tap, an expired link, an
 * empty drawer - comes back on the same round trip and must not be celebrated.
 * It awaits the half turn `pick()` started rather than starting its own: two
 * tweens on one property fight, and the loser is the one being watched.
 */
watch(
    () => props.reward,
    async (reward) => {
        if (reward === null || chosen.value === null) {
            return;
        }

        const element = innerEls.value[chosen.value];

        if (!element || reducedMotion()) {
            return;
        }

        if (edgeOn !== null) {
            await edgeOn;
        }

        if (waiting !== null) {
            waiting.kill();
            waiting = null;
            gsap.to(cardEls.value[chosen.value], {
                scale: 1.08,
                duration: 0.2,
            });
        }

        gsap.to(element, {
            rotationY: 360,
            duration: 0.3,
            ease: 'power2.out',
            onComplete: burst,
        });
    },
);

const COLOURS = ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#a855f7'];

const PIECES = 110;

/** Live confetti, so a page that goes away mid-celebration takes its tweens with it. */
const flying: HTMLElement[] = [];

/**
 * Confetti, hand-rolled on the GSAP the shuffle already needs.
 *
 * Thrown across the whole viewport rather than around the card: the host is
 * teleported to `body` and fixed, so no panel, card or `overflow` on the way
 * up clips it, and the celebration is the size of the screen it is on.
 *
 * Two throws per piece, which is what makes it read as celebration rather than
 * as a scatter - out from the card that was just turned over, filling the
 * screen, then down past the bottom edge under its own weight.
 */
function burst(): void {
    const host = burstHost.value;

    if (!host || reducedMotion()) {
        return;
    }

    const width = window.innerWidth;
    const height = window.innerHeight;

    /* Out of the card the customer is already looking at, so the celebration
       carries on from the reveal instead of arriving from nowhere. */
    const card = chosen.value === null ? null : cardEls.value[chosen.value];
    const from = card?.getBoundingClientRect();
    const originX = from ? from.left + from.width / 2 : width / 2;
    const originY = from ? from.top + from.height / 2 : height / 2;

    for (let i = 0; i < PIECES; i++) {
        const piece = document.createElement('span');
        const round = i % 3 === 0;
        const size = gsap.utils.random(7, 15);

        piece.className = round
            ? 'absolute top-0 left-0 rounded-full'
            : 'absolute top-0 left-0 rounded-[2px]';
        piece.style.width = `${size}px`;
        piece.style.height = `${round ? size : size * gsap.utils.random(0.45, 1)}px`;
        piece.style.backgroundColor = COLOURS[i % COLOURS.length];

        host.appendChild(piece);
        flying.push(piece);

        gsap.timeline({
            onComplete: () => {
                piece.remove();
                flying.splice(flying.indexOf(piece), 1);
            },
        })
            .fromTo(
                piece,
                {
                    x: originX,
                    y: originY,
                    opacity: 1,
                    rotation: gsap.utils.random(0, 360),
                    scale: gsap.utils.random(0.6, 1.2),
                },
                {
                    x: gsap.utils.random(0, width),
                    /* The top three fifths, so the screen fills upwards before
                       any of it starts coming down. */
                    y: gsap.utils.random(0, height * 0.6),
                    duration: gsap.utils.random(0.45, 0.9),
                    ease: 'power2.out',
                },
            )
            .to(piece, {
                y: height + 60,
                x: `+=${gsap.utils.random(-90, 90)}`,
                rotation: `+=${gsap.utils.random(-540, 540)}`,
                opacity: 0,
                duration: gsap.utils.random(1.1, 2.1),
                ease: 'power1.in',
            });
    }
}

/**
 * The unchosen cards go blank rather than keep their label: on the path where
 * nothing animates they were never turned over, and a table still showing five
 * labels beside a sixth answer reads as two cards holding the same thing.
 */
function faceOf(card: ShuffleCard, index: number): string {
    if (chosen.value === null) {
        return card.name;
    }

    return chosen.value === index && props.reward ? props.reward.name : '';
}

/**
 * The glyph follows whatever the face now says: tapping the installation card
 * and winning a discount shows the discount mark, not the spanner.
 */
function iconOf(card: ShuffleCard, index: number): Component {
    const type =
        chosen.value === index && props.reward ? props.reward.type : card.type;

    return rewardIcon(type);
}
</script>

<template>
    <div class="flex w-full flex-col items-center gap-4">
        <p
            v-if="phase === 'ready' && props.playable && !alreadyWon"
            class="text-sm text-muted-foreground"
            data-test="prompt-ready"
        >
            Click the button below to reveal your reward.
        </p>
        <p
            v-else-if="phase === 'shuffling'"
            class="text-sm text-muted-foreground"
        >
            Shuffling&hellip;
        </p>
        <!--
          Names no input device on purpose: this page runs on the customer's
          own phone and on the showroom monitor, and `(pointer: coarse)`
          reports the primary pointer, so it gets the showroom wrong about as
          often as it gets it right.
        -->
        <p
            v-else-if="phase === 'picking'"
            class="text-sm font-bold text-primary"
            data-test="prompt-pick"
        >
            Choose a card to reveal your reward
        </p>

        <!-- ===================== The won card ===================== -->
        <div
            v-if="alreadyWon"
            ref="table"
            class="flex w-full justify-center"
            data-test="won-card"
        >
            <span
                class="relative flex flex-col items-center justify-center gap-1.5 rounded-sm bg-cover bg-center px-[14%] py-[12%]"
                :style="{
                    backgroundImage: `url(${CARD_FRONT})`,
                    width: `${cardWidth}px`,
                    height: `${cardHeight}px`,
                }"
            >
                <component
                    :is="rewardIcon(props.reward?.type ?? '')"
                    class="text-amber-300"
                    :style="{
                        width: `${iconSize}px`,
                        height: `${iconSize}px`,
                    }"
                />
                <span
                    class="text-center leading-tight font-bold text-white"
                    :style="{ fontSize: `${labelSize}px` }"
                >
                    {{ props.reward?.name }}
                </span>
            </span>
        </div>

        <!-- ===================== The table ===================== -->
        <div
            v-else
            ref="table"
            class="relative w-full"
            :style="{ height: `${tableHeight}px` }"
            data-test="shuffle-table"
        >
            <button
                v-for="(card, index) in props.cards"
                :key="card.name"
                :ref="
                    (el) => {
                        if (el) cardEls[index] = el as HTMLElement;
                    }
                "
                type="button"
                class="absolute top-0 left-0 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="
                    phase === 'picking' ? 'cursor-pointer' : 'cursor-default'
                "
                :disabled="phase !== 'picking'"
                :aria-label="
                    phase === 'picking' ? `Pick card ${index + 1}` : card.name
                "
                :data-test="`card-${index}`"
                @click="pick(index)"
            >
                <!-- The turning half, separate from the button that fades - see `innerEls`. -->
                <span
                    :ref="
                        (el) => {
                            if (el) innerEls[index] = el as HTMLElement;
                        }
                    "
                    class="relative block size-full [transform-style:preserve-3d]"
                >
                    <span
                        class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 rounded-sm bg-cover bg-center px-[14%] py-[12%] [backface-visibility:hidden]"
                        :style="{ backgroundImage: `url(${CARD_FRONT})` }"
                    >
                        <component
                            :is="iconOf(card, index)"
                            class="text-amber-300"
                            :style="{
                                width: `${iconSize}px`,
                                height: `${iconSize}px`,
                            }"
                        />
                        <span
                            class="text-center leading-tight font-bold text-white"
                            :style="{ fontSize: `${labelSize}px` }"
                        >
                            {{ faceOf(card, index) }}
                        </span>
                    </span>

                    <!-- One back artwork for every card: a face-down card gives nothing away. -->
                    <span
                        class="absolute inset-0 [transform:rotateY(180deg)] rounded-sm bg-cover bg-center [backface-visibility:hidden]"
                        :style="{ backgroundImage: `url(${CARD_BACK})` }"
                    />
                </span>
            </button>
        </div>

        <!-- On `body` rather than in the table: fixed inside the page it would
             be clipped by the panel around it, and the celebration is meant to
             be the whole screen. Inside the root element all the same, so the
             component keeps its single root. -->
        <Teleport to="body">
            <div
                ref="burstHost"
                class="pointer-events-none fixed inset-0 z-100 overflow-hidden"
                aria-hidden="true"
            />
        </Teleport>

        <Button
            v-if="phase === 'ready' && props.playable"
            size="lg"
            class="w-full"
            data-test="shuffle-now"
            @click="begin"
        >
            Shuffle now
        </Button>
    </div>
</template>
