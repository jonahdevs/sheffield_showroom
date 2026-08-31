<script setup lang="ts">
import gsap from 'gsap';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { rewardIcon } from '@/lib/rewardIcons';

/**
 * The two faces of a card, both artwork.
 *
 * Served from `public` rather than imported through Vite, which is where this
 * application already keeps its static images - the favicons sit beside them.
 * Downscaled from the originals on the way in: a card is never drawn wider
 * than 132px, so shipping the 1200px sources to a phone would be half a
 * megabyte for detail nobody can see.
 *
 * The front carries a gold frame around an empty navy panel, which is where
 * the reward name goes - hence the inset on the label below, which keeps the
 * text inside the frame rather than printing it over the border.
 */
const CARD_FRONT = '/images/shuffle-card-front.jpg';
const CARD_BACK = '/images/shuffle-card-back.jpg';

/** One card face: the kind of thing that could come up, never how many. */
export type ShuffleCard = {
    name: string;
    type: string;
    type_label: string;
    /**
     * Read by the panel listing what is on offer rather than by the table -
     * a card is too small to carry either. Optional because the showroom
     * screen, which has no such panel, does not send them.
     */
    value?: string | null;
    terms?: string | null;
};

const props = defineProps<{
    cards: ShuffleCard[];
    /**
     * The reward, once it has been won. Its name and its kind - the kind
     * because the card that turns over has to show the won reward's glyph, and
     * that is rarely the glyph of the card that was tapped.
     */
    reward: { name: string; type: string } | null;
    /** Whether there is still a turn to take. */
    playable: boolean;
}>();

/**
 * The tap. The parent sends the request, because the two screens that use this
 * send different ones - the customer's phone posts to the public route, the
 * showroom screen posts to the staff one - and both end at the same service.
 */
const emit = defineEmits<{ pick: [] }>();

/*
 * Four phases, and whoever is holding the screen drives three of them:
 *
 *   ready     - the cards lie face up, so what is on the table can be read
 *               before anything moves. Nothing is hidden at the start.
 *   shuffling - they turn together and trade places.
 *   picking   - the cards stop, and one of them gets tapped.
 *   revealing - the tapped card turns over.
 *
 * WHAT THE TAP DOES, because it is the one thing here worth being exact about:
 * it does not choose the reward. `ShuffleRewardService` chose it on the server,
 * inside a transaction, out of what is physically left in the pool - and the
 * request is not sent until the tap, so there is nothing on the page to read
 * ahead of it either. Whichever card is touched turns over onto that answer.
 *
 * That is presentation, not a trick: the odds are real and they are the
 * inventory. What the tap buys is the feeling of having picked. The unchosen
 * cards are never turned over, because there is nothing behind them.
 */
type Phase = 'ready' | 'shuffling' | 'picking' | 'revealing';

const phase = ref<Phase>('ready');
const chosen = ref<number | null>(null);

/**
 * Whether this is a turn that was played before the page was loaded.
 *
 * `chosen` is component state, so a refresh forgets which card was tapped.
 * Without this the table comes back dealt face up with all five names on it -
 * as though nothing had happened - over a heading that says "Your reward" and
 * a panel showing the code. The customer is told they won and shown an
 * un-played game in the same breath.
 *
 * There is nothing to restore: which card they touched decided nothing and is
 * not worth a column. So a reload draws the one card that matters, face up,
 * and no table at all.
 */
const alreadyWon = computed(
    () => props.reward !== null && chosen.value === null,
);

/** Card index to the slot it currently occupies. */
const slotOf = ref<number[]>(props.cards.map((_, index) => index));

const table = ref<HTMLElement | null>(null);
const cardEls = ref<HTMLElement[]>([]);

/*
 * The turning half, held apart from the moving half.
 *
 * These have to be two elements. An element at less than full opacity flattens
 * its own 3D context, so the moment the unchosen cards fade back their
 * `backface-visibility` stops being honoured and the face shows through the
 * back, mirrored. Position, scale and opacity live on the button; only the
 * rotation lives in here.
 */
const innerEls = ref<HTMLElement[]>([]);
const burstHost = ref<HTMLElement | null>(null);
const tableWidth = ref(0);

/** Three to a row, so five cards read as three and two rather than a smear. */
const PER_ROW = 3;
const GAP = 16;
const RATIO = 1.4;

const perRow = computed(() => Math.min(props.cards.length || 1, PER_ROW));
const rows = computed(() =>
    Math.ceil((props.cards.length || 1) / perRow.value),
);

/**
 * How wide a card is drawn, between a floor and a ceiling.
 *
 * The floor keeps three cards tappable on the narrowest phone; the ceiling
 * stops them ballooning on the showroom screen, where the column has room for
 * far more than a playing card wants to be.
 */
const MIN_CARD = 84;
const MAX_CARD = 240;

/**
 * How many cards have to fit across, which is three - or one, on a turn that
 * was already played and now draws a single card. Dividing the table by three
 * there would draw that card at a third of the room it has.
 */
const across = computed(() => (alreadyWon.value ? 1 : perRow.value));

const cardWidth = computed(() => {
    const available = tableWidth.value - GAP * (across.value - 1);

    return Math.max(
        MIN_CARD,
        Math.min(MAX_CARD, Math.floor(available / across.value)),
    );
});

const cardHeight = computed(() => Math.round(cardWidth.value * RATIO));

/*
 * The glyph and the label are sized off the card rather than fixed.
 *
 * The card itself swings from 84px on the narrowest phone to 240px on a
 * showroom monitor - close to three times - and a fixed icon would be a
 * postage stamp at one end and swallow the card at the other. Both are
 * clamped, because type below about eleven pixels stops being readable across
 * a counter however much room there is.
 */
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

    /* The last row is centred rather than left-aligned, so two cards under
       three sit under the middle of them instead of hanging off one side. */
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
 * Put every card where its slot says, at whatever size the table is now.
 *
 * Deliberately says nothing about rotation. This runs again on every resize -
 * a phone turning, an address bar collapsing - and a version that reset the
 * rotation too would turn the whole table face up mid-shuffle.
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

    /* Face up to start with, once. */
    gsap.set(innerEls.value, { rotationY: 0 });

    observer = new ResizeObserver(measure);

    if (table.value) {
        observer.observe(table.value);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    gsap.killTweensOf([...cardEls.value, ...innerEls.value]);
});

/**
 * Whether to animate at all.
 *
 * Two reasons not to. The first is the setting, and is the obvious one.
 *
 * The second is that the page is not on screen: a browser throttles
 * `requestAnimationFrame` to about a frame a second in a background tab, which
 * is what GSAP runs on. Somebody who takes a call in the middle of a shuffle
 * would otherwise come back to a table still crawling, or worse to a
 * `shuffleCards()` that has not resolved and a phase stuck on "Shuffling" - so
 * a hidden page skips to the pick and loses nothing but the flourish.
 */
const reducedMotion = (): boolean =>
    document.hidden ||
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Turn every card face down together, then trade places a few times.
 *
 * The cards lift as they cross: a straight slide reads as a list re-sorting
 * itself, where an arc reads as hands moving cards over one another, which is
 * the thing being imitated.
 */
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

    for (let pass = 0; pass < 3; pass++) {
        /* A real permutation each pass rather than pairwise swaps, so no card
           can sit still through the whole shuffle and be followed. */
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
                        duration: 0.42,
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
 * The half turn that starts the moment a card is touched.
 *
 * Held so the reveal can wait for it rather than fighting it - see the watcher
 * below, which picks the card up from wherever this left it.
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

    /* The chosen card lifts and the others fade back, so the eye is already on
       the right card before the answer lands. */
    if (element) {
        gsap.to(element, { scale: 1.08, zIndex: 20, duration: 0.25 });
        gsap.to(
            cardEls.value.filter((_, other) => other !== index),
            { opacity: 0.25, scale: 0.94, duration: 0.3 },
        );
    }

    /*
     * And the card starts turning at once, on the tap rather than on the
     * answer.
     *
     * It used to stand still until `props.reward` arrived, which meant the
     * whole request sat visibly between the touch and any movement - the card
     * enlarged, face down, waiting. On a counter that reads as the game going
     * away to ask a computer something, which is exactly what it must not look
     * like.
     *
     * So it turns to 270 degrees and stops: edge-on, where a card is a line
     * and discloses nothing. The reward is still the server's to decide and
     * still has not been asked for - all this buys is that the round trip
     * happens behind a motion already underway rather than in front of a
     * motionless one.
     */
    if (inner) {
        edgeOn = gsap.to(inner, {
            rotationY: 270,
            duration: 0.3,
            ease: 'power2.in',
        });
    }
}

/**
 * The rest of the turn, once there is something to turn onto.
 *
 * Driven by the reward arriving rather than by the request finishing, because
 * a refusal - a double tap, an expired link, an empty drawer - comes back on
 * the same round trip and must not be celebrated.
 *
 * It waits for the half turn `pick()` started rather than starting its own:
 * two tweens on one property fight, and the loser is whichever the customer
 * was watching. Awaiting it also means a fast answer simply carries straight
 * on through the edge - the card never stops - while a slow one rests
 * side-on until it can be honoured.
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

        gsap.to(element, {
            rotationY: 360,
            duration: 0.3,
            ease: 'power2.out',
            onComplete: burst,
        });
    },
);

/*
 * Confetti, hand-rolled out of a dozen divs.
 *
 * A second dependency for fourteen squares that fall for a second and a half
 * was not worth it when GSAP is already here for the shuffle - and a canvas
 * library would have to be told about the page's theme, its safe areas and its
 * reduced-motion setting, all of which this already knows.
 */
function burst(): void {
    const host = burstHost.value;

    if (!host || reducedMotion()) {
        return;
    }

    const colours = ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#a855f7'];

    for (let i = 0; i < 14; i++) {
        const piece = document.createElement('span');

        piece.className = 'absolute size-2 rounded-[2px]';
        piece.style.backgroundColor = colours[i % colours.length];
        host.appendChild(piece);

        gsap.fromTo(
            piece,
            { x: 0, y: 0, opacity: 1, scale: 1 },
            {
                x: gsap.utils.random(-140, 140),
                y: gsap.utils.random(-160, 40),
                rotation: gsap.utils.random(-220, 220),
                opacity: 0,
                scale: gsap.utils.random(0.4, 1.1),
                duration: gsap.utils.random(0.9, 1.5),
                ease: 'power2.out',
                onComplete: () => piece.remove(),
            },
        );
    }
}

/**
 * What is written on a card's face.
 *
 * Before anybody picks, every card says what it is - that is the point of
 * dealing them face up. Afterwards only the chosen one says anything, and it
 * says what was won.
 *
 * The others go blank rather than keeping their label, because on the path
 * where nothing animates - a reduced-motion setting, or a page that was not on
 * screen - they were never turned over, and a table still showing five labels
 * beside a sixth answer reads as though two cards held the same thing.
 */
function faceOf(card: ShuffleCard, index: number): string {
    if (chosen.value === null) {
        return card.name;
    }

    return chosen.value === index && props.reward ? props.reward.name : '';
}

/**
 * The glyph on a card's face, following whatever that face is currently
 * saying.
 *
 * Which matters on the one card that turns over: tapping the installation card
 * and winning a discount has to show the discount mark, not the spanner that
 * happened to be printed on the card somebody reached for.
 */
function iconOf(card: ShuffleCard, index: number): Component {
    const type =
        chosen.value === index && props.reward ? props.reward.type : card.type;

    return rewardIcon(type);
}
</script>

<!--
  The table, shared by the customer's phone and the showroom screen.

  One component rather than two, for the same reason there is one
  `ShuffleRewardService` behind both: a second copy of this would be the one
  that quietly drifted, and it would be the one standing in front of the
  customer.
-->
<template>
    <div class="flex w-full flex-col items-center gap-4">
        <!--
          The opening line, and it points at the button rather than describing
          the table.

          It was asked for as "Click the button below to reveal your reward",
          and that is the sentence this is - minus the verb, which was the one
          word it could not keep. This component is held two ways: most
          customers scan the QR code and play on their own phone, where nothing
          is clicked, and the ones whose camera will not cooperate are handed
          the showroom monitor, which runs this very page. "Click" would have
          been wrong in front of the majority, and it would have sat two
          elements above a prompt that names no input device on purpose - see
          the note on `prompt-pick`, which sets out why the `(pointer: coarse)`
          branch that would let both lines name a gesture accurately was
          rejected. One prompt cannot spend that argument and the next one
          ignore it. "Use" covers a finger, a mouse and the keyboard the focus
          ring below already supports, exactly as "Choose" does further down.

          The other half of the old line - "here is what is on the table" - is
          gone rather than kept, which was the harder call. It was narration:
          the cards are dealt face up with their names printed across them
          precisely so that the table can be read before anything moves (see
          the note on `Phase`), and a sentence telling somebody to look at what
          they are already looking at is a sentence spent. The panel headed
          "What you could win" in `pages/rewards/Shuffle.vue` says the same
          thing in full, with values, for anybody who wants it in words. The
          cost is on a phone, where that panel stacks below the table and is
          not on screen at first glance - but the face-up cards are, and they
          are the better answer anyway. What replaces the narration is the one
          thing five pieces of artwork genuinely cannot say: that this ends in
          a reward, and that the button is how it starts.
        -->
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
          The one instruction on the screen, and it deliberately names no
          input device.

          This table is held two ways. Most customers scan the QR code and
          play on their own phone, where the verb is "tap"; the ones whose
          camera will not cooperate are handed the showroom monitor, which
          runs this very page - see the "Shuffle on this screen" button on the
          staff screen - and there the verb is "click". "Tap a card" was
          simply wrong on the second of those, in front of the customer least
          able to work out what was meant.

          The considered alternative was reading `(pointer: coarse)` at the
          moment the prompt appears and printing "Tap" or "Click" to match.
          It was rejected on grounds of accuracy rather than effort: that
          query reports the *primary* pointer, so a showroom monitor with a
          touch panel answers "coarse" while somebody drives it with a mouse,
          and a touchscreen laptop flips its answer when it is docked. It
          would have been a branch that got the showroom - the case it exists
          for - wrong about as often as it got it right, plus a
          `window.matchMedia` guard for the browsers that lack it, all to
          choose between two words.

          Naming the reward instead of the gesture also does more work than
          the old line did: it says what picking a card is *for*, which is the
          one thing a customer looking at five identical backs cannot infer.
          "Choose" covers a finger, a mouse and the keyboard the focus ring
          below already supports.
        -->
        <p
            v-else-if="phase === 'picking'"
            class="text-sm font-bold text-primary"
            data-test="prompt-pick"
        >
            Choose a card to reveal your reward
        </p>

        <!--
          A turn played before this page existed - see `alreadyWon`. One card,
          face up, at the size the table would have drawn it.
        -->
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
                <!--
                  The turning half. Separate from the button because the button
                  is what fades, and a faded element flattens its own 3D
                  context - see the note on `innerEls`.
                -->
                <span
                    :ref="
                        (el) => {
                            if (el) innerEls[index] = el as HTMLElement;
                        }
                    "
                    class="relative block size-full [transform-style:preserve-3d]"
                >
                    <!--
                      The face. The label sits inside the artwork's gold frame
                      rather than across the whole card: the panel it is
                      printed on stops about a tenth of the way in on every
                      side, and text over that border is text nobody can read.
                    -->
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

                    <!--
                      The back: one artwork for every card, so nothing about a
                      face-down card says which one it was.

                      A background image rather than an `img`, because it is
                      decoration - it carries no information a screen reader
                      needs, and the card already announces itself through the
                      button's label. `cover` on a 440px source: the card is
                      drawn at 132px at most, so even a three-times display has
                      more pixels than it can use.
                    -->
                    <span
                        class="absolute inset-0 [transform:rotateY(180deg)] rounded-sm bg-cover bg-center [backface-visibility:hidden]"
                        :style="{ backgroundImage: `url(${CARD_BACK})` }"
                    />
                </span>
            </button>

            <!-- Fired from the middle of the table rather than from the card,
                 so it reads as the moment rather than as something the card
                 did. -->
            <span
                ref="burstHost"
                class="pointer-events-none absolute top-1/2 left-1/2 z-30"
                aria-hidden="true"
            />
        </div>

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
