<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <header class="relative z-10 flex items-center gap-3 border-b border-slate-200/80 bg-white/80 px-4 py-2 shadow-sm backdrop-blur-xl dark:border-slate-800/70 dark:bg-slate-950/70">
        <div class="flex items-center gap-2">
            <Link :href="fm.panelRoute('websites.manage', { id: fm.props.website.id })" class="flex items-center gap-2 rounded-xl border border-slate-200/70 bg-white/70 px-2 py-1 shadow-sm transition-all hover:-translate-y-0.5 hover:bg-white dark:border-slate-700 dark:bg-slate-800/70 dark:hover:bg-slate-800">
                <img src="/sm_logo.png" alt="dPanel" class="h-6 w-auto" />
                <div class="flex h-6 w-6 items-center justify-center rounded-md bg-blue-100 dark:bg-blue-900/30">
                    <i class="bi bi-folder-fill text-xs text-blue-600 dark:text-blue-400"></i>
                </div>
            </Link>
        </div>

        <div class="h-5 w-px bg-slate-200 dark:bg-slate-700"></div>

        <div class="flex min-w-0 flex-1 items-center gap-2">
            <button
                type="button"
                class="rounded-lg border border-slate-300/80 bg-white/70 px-2 py-1.5 shadow-sm transition hover:-translate-y-0.5 hover:bg-white lg:hidden dark:border-slate-700 dark:bg-slate-800/70 dark:hover:bg-slate-700"
                @click="fm.toggleSidebar()"
            >
                <i class="bi bi-list text-sm"></i>
            </button>

            <Link :href="fm.panelRoute('websites.manage', { id: fm.props.website.id })" class="flex items-center gap-1.5 text-sm font-semibold hover:underline">
                {{ fm.websiteLabel }}
            </Link>
            <span class="text-slate-400">/</span>
            <div v-if="fm.props.basePath" class="flex items-center gap-2 rounded-full border border-sky-200/80 bg-sky-50/90 px-2.5 py-1 text-[11px] text-sky-700 shadow-sm dark:border-sky-800/60 dark:bg-sky-900/20 dark:text-sky-300">
                <i class="bi bi-diagram-3"></i>
                <span class="max-w-40 truncate font-medium">Root: {{ fm.resolveDisplayPath(fm.props.basePath) }}</span>
                <button type="button" class="rounded-full border border-sky-200 px-1.5 py-0.5 text-[10px] transition hover:bg-sky-100 dark:border-sky-800 dark:hover:bg-sky-900/40" @click="fm.resetRootScope">
                    Reset
                </button>
            </div>

            <div class="flex min-w-0 items-center gap-1 overflow-x-auto text-sm">
                <button type="button" class="shrink-0 rounded-md px-1.5 py-1 transition hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.goRoot">
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
        </div>

        <div class="flex items-center gap-2">
            <div class="hidden items-center md:flex">
                <div class="relative">
                    <i class="bi bi-search pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input
                        v-model="fm.searchQuery"
                        type="text"
                        class="w-40 rounded-xl border border-slate-200/80 bg-white/80 py-1.5 pl-7 pr-2 text-xs shadow-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-2 focus:ring-sky-400/15 dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-300 dark:focus:bg-slate-800"
                        placeholder="Search files..."
                    >
                </div>
            </div>

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
                            @click="item.action()"
                        >
                            <i class="bi text-sm" :class="[item.icon, item.className]"></i>
                            <span>{{ item.label }}</span>
                        </button>
                    </section>
                </template>
            </div>

            <Link
                :href="fm.panelRoute('websites.manage', { id: fm.props.website.id })"
                class="flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 transition-all hover:border-red-300 hover:bg-red-100 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30"
            >
                <i class="bi bi-box-arrow-left text-sm"></i>
                <span class="hidden sm:inline">Exit</span>
            </Link>
        </div>
    </header>
</template>
