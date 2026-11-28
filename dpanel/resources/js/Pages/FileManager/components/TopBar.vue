<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SiteSwitcher from './SiteSwitcher.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    fm: {
        type: Object,
        required: true,
    },
});

const liveSiteUrl = computed(() => {
    const domain = String(props.fm.props.website?.domain || '').trim();
    if (!domain) return '';
    return `${props.fm.props.website?.enable_ssl ? 'https' : 'http'}://${domain}`;
});

const trimSlashes = (value) => String(value || '').replace(/\\/g, '/').replace(/\/+$/, '');

// The URL that serves the folder being viewed: the site whose document root
// is the deepest prefix of it, preferring this website when aliases share one.
const currentFolderUrl = computed(() => {
    const sites = Array.isArray(props.fm.props.browseSites) ? props.fm.props.browseSites : [];
    const base = trimSlashes(props.fm.props.basePath);
    const current = trimSlashes(props.fm.props.currentPath).replace(/^\/+/, '');
    const absolute = current ? `${base}/${current}` : base;
    const currentId = String(props.fm.props.website?.id || '');

    let match = null;
    for (const site of sites) {
        const root = trimSlashes(site.doc_root);
        if (!root || !site.url) continue;
        if (absolute !== root && !absolute.startsWith(`${root}/`)) continue;
        const better = !match
            || root.length > match.root.length
            || (root.length === match.root.length && String(site.id) === currentId);
        if (better) match = { site, root };
    }
    // Folders no site serves (.cache, .venv, ...) fall back to this site's home page.
    if (!match) return liveSiteUrl.value ? `${liveSiteUrl.value}/` : '';

    const relative = absolute.slice(match.root.length).split('/').filter(Boolean).map(encodeURIComponent).join('/');
    return relative ? `${match.site.url}/${relative}/` : `${match.site.url}/`;
});
</script>

<template>
    <header class="relative z-10 flex items-center gap-3 border-b border-slate-200/80 bg-white/80 px-4 py-2 shadow-sm backdrop-blur-xl dark:border-slate-800/70 dark:bg-slate-950/70">
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <Link
                :href="fm.panelRoute('websites.manage', { id: fm.props.website.id })"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-600 transition-all hover:border-red-300 hover:bg-red-100 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30"
                :title="t('fileManager.topBar.exit')"
            >
                <i class="bi bi-box-arrow-left text-sm"></i>
            </Link>

            <button
                type="button"
                :aria-label="t('fileManager.topBar.toggleSidebar')"
                class="rounded-lg border border-slate-300/80 bg-white/70 px-2 py-1.5 shadow-sm transition hover:-translate-y-0.5 hover:bg-white lg:hidden dark:border-slate-700 dark:bg-slate-800/70 dark:hover:bg-slate-700"
                @click="fm.toggleSidebar()"
            >
                <i class="bi bi-list text-sm"></i>
            </button>

            <div class="flex min-w-0 items-center gap-1 overflow-x-auto text-sm">
                <button type="button" :aria-label="t('fileManager.topBar.root')" class="shrink-0 rounded-md px-1.5 py-1 transition hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.goRoot">
                    <i class="bi bi-house-door text-xs"></i>
                </button>
                <template v-for="(segment, idx) in (fm.props.currentPath || '').split('/').filter(Boolean)" :key="idx">
                    <i class="bi bi-chevron-right text-[10px] text-slate-400"></i>
                    <button
                        type="button"
                        class="shrink-0 truncate rounded-md px-1.5 py-1 transition hover:bg-slate-100 dark:hover:bg-slate-800"
                        @click="fm.openPath((fm.props.currentPath || '').split('/').filter(Boolean).slice(0, idx + 1).join('/'))"
                    >
                        {{ segment }}
                    </button>
                </template>
            </div>
            <SiteSwitcher :fm="fm" />
            <a
                v-if="currentFolderUrl"
                :href="currentFolderUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-600 transition-all hover:border-emerald-300 hover:bg-emerald-100 dark:border-emerald-900/50 dark:bg-emerald-900/20 dark:text-emerald-400 dark:hover:bg-emerald-900/30"
                :title="t('fileManager.topBar.browse', { url: currentFolderUrl })"
                :aria-label="t('fileManager.topBar.browse', { url: currentFolderUrl })"
            >
                <i class="bi bi-globe2 text-xs"></i>
            </a>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                class="flex items-center gap-2 rounded-xl border border-slate-200/80 bg-white/80 py-1.5 pl-2.5 pr-2 text-xs text-slate-400 shadow-sm transition hover:border-sky-400 hover:text-slate-600 dark:border-slate-700 dark:bg-slate-800/80 dark:hover:text-slate-200"
                :title="t('fileManager.palette.openHint')"
                @click="fm.openPalette()"
            >
                <i class="bi bi-search text-xs"></i>
                <span class="hidden lg:inline">{{ t('fileManager.palette.trigger') }}</span>
                <kbd class="rounded border border-slate-200 px-1 py-0.5 font-sans text-[9px] dark:border-slate-600">Ctrl K</kbd>
            </button>

            <div class="flex min-w-0 flex-1 items-center justify-end gap-2 overflow-x-auto">
                <template v-for="(group, groupIndex) in (Array.isArray(fm.quickActionsGroups) ? fm.quickActionsGroups.filter(Boolean) : [])" :key="group?.label || groupIndex">
                    <section
                        v-if="group && Array.isArray(group.items) && group.items.length"
                        class="flex shrink-0 items-center gap-1.5 rounded-2xl border border-slate-200/70 bg-white/75 px-2 py-1 shadow-sm backdrop-blur dark:border-slate-700/70 dark:bg-slate-900/40"
                    >
                        <button
                            v-for="item in (group.items || [])"
                            :key="item.label"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl px-2.5 py-1.5 text-xs font-medium transition-all duration-150 hover:-translate-y-0.5 hover:bg-slate-100 dark:hover:bg-slate-800"
                            :class="item.danger ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-slate-200'"
                            :title="`${group.label}: ${item.label}`"
                            :disabled="item.disabled"
                            @click="item.action()"
                        >
                            <i class="bi text-sm" :class="[item.icon, item.className]"></i>
                            <span>{{ item.label }}</span>
                        </button>
                    </section>
                </template>
            </div>
        </div>
    </header>
</template>
