import { computed, reactive, watch } from 'vue';

/**
 * Hash Forge's look is configurable: accent colour, density, card style,
 * corner radius and the value font. One reactive object, shared by every
 * tab (module-level singleton) and saved per browser so it survives reloads.
 *
 * Accent classes are written out in full here, never built by string
 * interpolation, so Tailwind's content scan actually emits them — that is
 * what makes the colour options work instead of silently doing nothing.
 */
const ACCENTS = {
    teal: {
        solid: 'bg-teal-600 hover:bg-teal-700 text-white',
        chip: 'border-teal-500 bg-teal-50 text-teal-700 dark:border-teal-400 dark:bg-teal-500/15 dark:text-teal-200',
        text: 'text-teal-700 dark:text-teal-300',
        ring: 'accent-teal-600',
        dot: 'bg-teal-500',
    },
    blue: {
        solid: 'bg-blue-600 hover:bg-blue-700 text-white',
        chip: 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-400 dark:bg-blue-500/15 dark:text-blue-200',
        text: 'text-blue-700 dark:text-blue-300',
        ring: 'accent-blue-600',
        dot: 'bg-blue-500',
    },
    violet: {
        solid: 'bg-violet-600 hover:bg-violet-700 text-white',
        chip: 'border-violet-500 bg-violet-50 text-violet-700 dark:border-violet-400 dark:bg-violet-500/15 dark:text-violet-200',
        text: 'text-violet-700 dark:text-violet-300',
        ring: 'accent-violet-600',
        dot: 'bg-violet-500',
    },
    emerald: {
        solid: 'bg-emerald-600 hover:bg-emerald-700 text-white',
        chip: 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:border-emerald-400 dark:bg-emerald-500/15 dark:text-emerald-200',
        text: 'text-emerald-700 dark:text-emerald-300',
        ring: 'accent-emerald-600',
        dot: 'bg-emerald-500',
    },
    rose: {
        solid: 'bg-rose-600 hover:bg-rose-700 text-white',
        chip: 'border-rose-500 bg-rose-50 text-rose-700 dark:border-rose-400 dark:bg-rose-500/15 dark:text-rose-200',
        text: 'text-rose-700 dark:text-rose-300',
        ring: 'accent-rose-600',
        dot: 'bg-rose-500',
    },
    amber: {
        solid: 'bg-amber-500 hover:bg-amber-600 text-white',
        chip: 'border-amber-500 bg-amber-50 text-amber-700 dark:border-amber-400 dark:bg-amber-500/15 dark:text-amber-200',
        text: 'text-amber-700 dark:text-amber-300',
        ring: 'accent-amber-500',
        dot: 'bg-amber-500',
    },
    slate: {
        solid: 'bg-slate-800 hover:bg-slate-900 text-white dark:bg-slate-200 dark:text-slate-900 dark:hover:bg-white',
        chip: 'border-slate-500 bg-slate-100 text-slate-800 dark:border-slate-400 dark:bg-slate-500/20 dark:text-slate-100',
        text: 'text-slate-800 dark:text-slate-200',
        ring: 'accent-slate-700',
        dot: 'bg-slate-500',
    },
};

const DENSITY = {
    compact: { card: 'p-3', gap: 'gap-3', stack: 'space-y-3' },
    cozy: { card: 'p-4', gap: 'gap-4', stack: 'space-y-4' },
    roomy: { card: 'p-6', gap: 'gap-6', stack: 'space-y-6' },
};

const RADIUS = { sharp: 'rounded-md', soft: 'rounded-xl', round: 'rounded-2xl' };

const CARD = {
    bordered: 'border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800',
    filled: 'border border-transparent bg-slate-100 dark:bg-slate-800/70',
    elevated: 'border border-slate-100 bg-white shadow-md shadow-slate-200/60 dark:border-slate-700 dark:bg-slate-800 dark:shadow-black/30',
};

const DEFAULTS = { accent: 'teal', density: 'cozy', radius: 'soft', card: 'bordered', mono: false };
const STORAGE_KEY = 'hashforge.appearance';

const state = reactive({ ...DEFAULTS });

try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    Object.assign(state, DEFAULTS, typeof saved === 'object' && saved ? saved : {});
} catch {
    // Private mode or blocked storage: the defaults stand.
}

watch(state, (value) => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch {
        // Nothing to do if the write is refused; the session still works.
    }
}, { deep: true });

export const ACCENT_OPTIONS = Object.keys(ACCENTS);
export const DENSITY_OPTIONS = Object.keys(DENSITY);
export const RADIUS_OPTIONS = Object.keys(RADIUS);
export const CARD_OPTIONS = Object.keys(CARD);

export function useAppearance() {
    const accent = computed(() => ACCENTS[state.accent] || ACCENTS.teal);
    const density = computed(() => DENSITY[state.density] || DENSITY.cozy);
    const radius = computed(() => RADIUS[state.radius] || RADIUS.soft);

    // One card's container classes: card style + radius + padding together.
    const cardClass = computed(() => `${CARD[state.card] || CARD.bordered} ${radius.value} ${density.value.card}`);
    const valueFont = computed(() => (state.mono ? 'font-mono' : 'font-sans'));

    const reset = () => Object.assign(state, DEFAULTS);

    return { appearance: state, accent, density, radius, cardClass, valueFont, reset };
}
