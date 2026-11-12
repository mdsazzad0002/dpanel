<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ToolTile from './ToolTile.vue';
import { clearPageUsage, compareUsage, normalizeLink, readPageUsage } from '@/composables/usePageUsage';

const props = defineProps({
    sections: { type: Array, required: true },
    userId: { type: [Number, String], default: null },
    // 2 rows everywhere: 8 tiles (4 columns) up to xl, 12 tiles (6 columns) on 2xl.
    limit: { type: Number, default: 12 },
    compactLimit: { type: Number, default: 8 },
});

const { t } = useI18n();

// Clicks are recorded in the browser by app.js; this card only reads them.
const usage = ref(readPageUsage(props.userId));

const panelToken = usePage().props.panel?.token;
const hrefFor = (path) => (panelToken ? `/cpsess${panelToken}${path}` : path);

// Pages for one website or database (Manage, Terminal, Edit…) aren't sidebar
// tools, but still earn a tile once clicked. app.js stores their title.
const pageKinds = [
    { prefix: '/websites/', iconClass: 'bi bi-globe2', color: 'emerald' },
    { prefix: '/databases/', iconClass: 'bi bi-database', color: 'amber' },
];

const items = computed(() => {
    const all = props.sections.flatMap((section) => section.items.map((item, index) => {
        const entry = usage.value[normalizeLink(item.href)];
        return { ...item, color: section.color, first: index === 0, entry, visits: entry?.count ?? 0 };
    }));

    const toolPaths = new Set(all.map((item) => normalizeLink(item.href)));
    const pages = Object.entries(usage.value)
        .filter(([path, entry]) => entry?.title && !toolPaths.has(path))
        .map(([path, entry]) => {
            const kind = pageKinds.find((k) => path.startsWith(k.prefix));
            if (!kind) return null;
            const label = entry.subject && !entry.title.includes(entry.subject) ? `${entry.title} - ${entry.subject}` : entry.title;
            return { label, href: hrefFor(path), iconClass: kind.iconClass, color: kind.color, entry, visits: entry.count ?? 0 };
        })
        .filter(Boolean);

    const used = [...all.filter((item) => item.visits > 0), ...pages].sort((a, b) => compareUsage(a.entry, b.entry));
    // Until enough has been clicked, top up with the first tool of each section.
    const defaults = all.filter((item) => item.first);

    const picked = [];
    for (const item of [...used, ...defaults]) {
        if (picked.length >= props.limit) break;
        if (!picked.includes(item)) picked.push(item);
    }
    return picked;
});

const hasUsage = computed(() => items.value.some((item) => item.visits > 0));

const reset = () => {
    clearPageUsage(props.userId);
    usage.value = {};
};
</script>

<template>
    <section v-if="items.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
        <header class="flex items-center gap-2.5 border-b border-slate-100 px-3 py-2.5 sm:px-4 dark:border-white/[0.06]">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-cyan-400 text-slate-950">
                <i class="bi bi-lightning-charge-fill"></i>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-slate-900 dark:text-slate-50">{{ t('dashboard.quickAccess') }}</span>
                <span class="block truncate text-[11px] text-slate-500 dark:text-slate-400">
                    {{ hasUsage ? t('dashboard.quickAccessUsed') : t('dashboard.quickAccessSuggested') }}
                </span>
            </span>
            <button
                v-if="hasUsage"
                type="button"
                class="flex h-8 shrink-0 items-center gap-1 rounded-lg px-2 text-xs font-medium text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/[0.06] dark:hover:text-slate-200"
                :title="t('dashboard.forgetClicks')"
                @click="reset"
            >
                <i class="bi bi-arrow-counterclockwise"></i><span class="hidden min-[400px]:inline">{{ t('dashboard.reset') }}</span>
            </button>
        </header>

        <div class="grid grid-cols-1 gap-1 p-2 min-[440px]:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6">
            <ToolTile
                v-for="(item, index) in items"
                :key="item.href"
                :item="item"
                :color="item.color"
                :class="index >= compactLimit && 'hidden 2xl:flex'"
            >
                <span
                    v-if="item.visits"
                    class="ml-auto shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-slate-500 dark:bg-white/[0.06] dark:text-slate-400"
                    :title="t('dashboard.clicked', { count: item.visits }, item.visits)"
                >{{ item.visits }}×</span>
            </ToolTile>
        </div>
    </section>
</template>
