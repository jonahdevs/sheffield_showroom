import { ref } from 'vue';

export type ChartTheme = {
    isDark: boolean;
    label: string;
    grid: string;
    /** The card a chart sits on, used as the seam between donut wedges. */
    surface: string;
    accent: string;
};

/* Fixed rather than read off `--chart-1..5`: those are five against nine
   purposes, and a different five hues in dark mode than in light. These sit at
   one lightness, so no wedge disappears into a white card or a near-black. */
export const CHART_PALETTE = [
    'hsl(354, 68%, 50%)',
    'hsl(229, 52%, 48%)',
    'hsl(173, 58%, 39%)',
    'hsl(38, 92%, 50%)',
    'hsl(262, 60%, 58%)',
    'hsl(199, 82%, 45%)',
    'hsl(150, 55%, 40%)',
    'hsl(330, 65%, 55%)',
    'hsl(215, 16%, 47%)',
];

const LIGHT: ChartTheme = {
    isDark: false,
    label: '#475569',
    grid: '#e2e8f0',
    surface: '#ffffff',
    accent: '#c12534',
};

const DARK: ChartTheme = {
    isDark: true,
    label: '#64748b',
    grid: '#1e293b',
    surface: '#0f172a',
    accent: '#c12534',
};

const theme = ref<ChartTheme>(LIGHT);

let observing = false;

/** The design tokens a chart needs, as the hex Apex demands - see `toHex`. */
export function useChartTheme() {
    watchDocumentTheme();

    return { theme };
}

function watchDocumentTheme(): void {
    if (observing || typeof document === 'undefined') {
        return;
    }

    observing = true;
    theme.value = readTheme();

    /* Never disposed, deliberately: tearing the observer down with whichever
       chart mounted first would strand every other chart in the old palette. */
    new MutationObserver(() => {
        theme.value = readTheme();
    }).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
}

function readTheme(): ChartTheme {
    const root = document.documentElement;
    const fallback = root.classList.contains('dark') ? DARK : LIGHT;
    const styles = getComputedStyle(root);

    return {
        isDark: fallback.isDark,
        label: token(styles, '--faint', fallback.label),
        grid: token(styles, '--border', fallback.grid),
        surface: token(styles, '--card', fallback.surface),
        accent: token(styles, '--primary', fallback.accent),
    };
}

/* The `var(` guard is for the browser that hands back the declaration rather
   than the substituted value: a `var(...)` fill paints nothing at all. */
function token(
    styles: CSSStyleDeclaration,
    name: string,
    fallback: string,
): string {
    const value = styles.getPropertyValue(name).trim();

    if (value === '' || value.includes('var(')) {
        return fallback;
    }

    return toHex(value) ?? fallback;
}

let canvas: CanvasRenderingContext2D | null | undefined;

/* Keyed on the colour itself rather than the token it came from, so nothing
   has to empty it when the theme flips - a stale entry is impossible. */
const hexes = new Map<string, string | null>();

/**
 * Any CSS colour as `#rrggbb`, painted rather than parsed.
 *
 * Apex derives tints, shades and gradient stops arithmetically from hex
 * digits, so the `hsl(354, 68%, 45%)` that `--primary` computes to has its
 * letters read as hex and the fill goes teal - while the stroke, which SVG
 * renders directly, looks right. The pixel is read back rather than
 * `fillStyle`, which hands an `oklch()` token back unconverted.
 */
function toHex(value: string): string | null {
    const cached = hexes.get(value);

    if (cached !== undefined) {
        return cached;
    }

    const hex = paint(value);

    hexes.set(value, hex);

    return hex;
}

function paint(value: string): string | null {
    const context = paintingContext();

    if (context === null || !accepts(context, value)) {
        return null;
    }

    context.clearRect(0, 0, 1, 1);
    context.fillRect(0, 0, 1, 1);

    const [red, green, blue] = context.getImageData(0, 0, 1, 1).data;

    return `#${[red, green, blue]
        .map((channel) => channel.toString(16).padStart(2, '0'))
        .join('')}`;
}

/* A `fillStyle` the canvas cannot parse is discarded in silence and the
   previous fill stands, so a garbled token would paint whatever the last one
   did. Two sentinels separate the cases: a real black cannot come back white. */
function accepts(context: CanvasRenderingContext2D, value: string): boolean {
    context.fillStyle = '#000000';
    context.fillStyle = value;

    if (context.fillStyle !== '#000000') {
        return true;
    }

    context.fillStyle = '#ffffff';
    context.fillStyle = value;

    return context.fillStyle !== '#ffffff';
}

function paintingContext(): CanvasRenderingContext2D | null {
    if (canvas === undefined) {
        if (typeof document === 'undefined') {
            canvas = null;
        } else {
            const element = document.createElement('canvas');

            element.width = 1;
            element.height = 1;

            canvas = element.getContext('2d', { willReadFrequently: true });
        }
    }

    return canvas;
}
