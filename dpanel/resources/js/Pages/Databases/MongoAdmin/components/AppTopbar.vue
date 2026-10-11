<script setup>
import { preview } from '../lib/mongoJson';

// The standalone app's top bar: menu (mobile), breadcrumb back up the tree,
// refresh and the light / dark switch.
defineProps({
    // A single database's login has no Server level.
    scoped: { type: Boolean, default: false },
    location: { type: Object, required: true },
    loading: { type: Boolean, default: false },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['go', 'refresh', 'toggle-theme', 'toggle-mobile-sidebar']);

const crumb = 'truncate rounded-md px-2 py-1 hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-slate-100';
const iconButton = 'flex h-9 w-9 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100';
</script>

<template>
    <header class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-2 border-b border-slate-200 bg-white/90 px-3 backdrop-blur sm:px-5 dark:border-slate-800 dark:bg-slate-950/90">
        <button type="button" :class="[iconButton, 'md:hidden']" aria-label="Open sidebar" @click="emit('toggle-mobile-sidebar')"><i class="bi bi-list text-lg"></i></button>

        <ol class="flex min-w-0 flex-1 items-center gap-0.5 text-sm text-slate-500 dark:text-slate-400">
            <li v-if="!scoped" class="min-w-0"><button type="button" :class="[crumb, !location.db && 'font-medium text-slate-900 dark:text-white']" @click="emit('go')"><i class="bi bi-hdd-rack mr-1"></i>Server</button></li>
            <template v-if="location.db">
                <li v-if="!scoped"><i class="bi bi-chevron-right text-[10px]"></i></li>
                <li class="min-w-0"><button type="button" :class="[crumb, !location.collection && 'font-medium text-slate-900 dark:text-white']" @click="emit('go', location.db)">{{ location.db }}</button></li>
            </template>
            <template v-if="location.collection">
                <li><i class="bi bi-chevron-right text-[10px]"></i></li>
                <li class="min-w-0"><button type="button" :class="[crumb, !location.doc && 'font-medium text-slate-900 dark:text-white']" @click="emit('go', location.db, location.collection)">{{ location.collection }}</button></li>
            </template>
            <template v-if="location.doc">
                <li><i class="bi bi-chevron-right text-[10px]"></i></li>
                <li class="min-w-0 truncate px-2 font-mono text-xs font-medium text-slate-900 dark:text-white">{{ location.doc === 'new' ? 'New document' : preview(location.doc, 32) }}</li>
            </template>
        </ol>

        <button type="button" :class="iconButton" :disabled="loading" title="Refresh" @click="emit('refresh')">
            <i class="bi bi-arrow-clockwise inline-block" :class="loading && 'animate-spin'"></i>
        </button>
        <button type="button" :class="iconButton" :title="isDark ? 'Light mode' : 'Dark mode'" @click="emit('toggle-theme')">
            <i class="bi" :class="isDark ? 'bi-sun' : 'bi-moon-stars'"></i>
        </button>
    </header>
</template>
