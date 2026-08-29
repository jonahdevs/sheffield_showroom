import { ref } from 'vue';

export type ChartTheme = {
    isDark: boolean;
    /** Axis ticks and anything else the chart writes for itself. */
    label: string;
    /** Grid lines. */
    grid: string;
    /** The card a chart sits on, used as the seam between donut wedges. */
    surface: string;
    /** The single colour a one-series chart is drawn in. */
    accent: string;
};

/**
 * The wedge colours, in the order a donut spends them.
 *
 * Fixed rather than read off `--chart-1..5`: those are shadcn's defaults, they
 * are a different five hues in dark mode than in light, and there are five of
 * them against nine purposes. These are picked at one lightness so no wedge
 * disappears into a white card or a near-black one, and they open on the two
 * brand colours so the ring belongs to the rest of the application.
 */
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

/**
 * The design tokens a chart needs, in colours a canvas can actually paint.
 *
 * ApexCharts writes these straight into SVG attributes and shades some of them
 * arithmetically, so `var(--faint)` is no use to it and neither is the
 * `hsl(...)` the token resolves to — the values have to arrive as hex. They are
 * read back off the document rather than duplicated here so a token that moves
 * moves the charts with it, and a hardcoded pair stands behind them for the
 * case where the stylesheet has not landed yet.
 */
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

    /*
      Never disposed, and deliberately so: appearance is an application-wide
      concern, and tearing the observer down with whichever chart happened to
      mount first would leave every other chart stuck in the old palette.
    */
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

/**
 * A custom property's computed value as `#rrggbb`, or the fallback.
 *
 * The `var(` guard is for the browser that hands back the declaration rather
 * than the substituted value: a fill of `var(--color-slate-600)` paints
 * nothing at all, which is worse than being one shade off.
 */
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

/*
  Keyed on the colour itself rather than on the token it came from, so nothing
  has to remember to empty it when the theme flips: `hsl(...)` to `#rrggbb` is
  the same answer in either appearance, and a stale entry is impossible.
*/
const hexes = new Map<string, string | null>();

/**
 * Any CSS colour as `#rrggbb`, painted rather than parsed.
 *
 * Apex reads every colour it is given with a hex reader and derives its
 * gradient stops, tints and shades arithmetically from the digits. Hand it the
 * `hsl(354, 68%, 45%)` that `--primary` computes to and it reads the letters as
 * hex: the wash under the trend line came out fading to a teal that appears
 * nowhere in the palette. SVG itself is happy with `hsl()` and `oklch()`, which
 * is why the stroke looked right while the fill did not.
 *
 * A canvas is used because it accepts every syntax the stylesheet might grow
 * into. The pixel is read back rather than `fillStyle`, which no longer
 * normalises a wide-gamut value and would hand an `oklch()` token straight
 * back unconverted.
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

/**
 * Whether the canvas understood the colour, and left it as the fill.
 *
 * A `fillStyle` it cannot parse is discarded in silence and the previous fill
 * stands, so a garbled token would paint whatever the last one did. Offering it
 * against two different sentinels separates the two cases: a value that lands
 * on whichever sentinel it was offered against is one the canvas ignored, and a
 * genuine black cannot come back white.
 */
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
