<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatBytes, formatCount } from '../lib/useMongoAdmin';

// The fixed left rail of the standalone Mongo Admin app, like dPanel Mail's:
// the server, then every database with its collections.
const props = defineProps({
    databases: { type: Array, default: () => [] },
    location: { type: Object, required: true },
    version: { type: String, default: null },
    collapsed: { type: Boolean, default: false },
    mobileOpen: { type: Boolean, default: false },
    panelHref: { type: String, required: true },
    serviceHref: { type: String, required: true },
});

const emit = defineEmits(['go', 'toggle-collapse', 'close-mobile']);

const filter = ref('');
const open = ref(new Set(props.location.db ? [props.location.db] : []));
const compact = computed(() => props.collapsed && !props.mobileOpen);

watch(() => props.location.db, (db) => {
    if (db) open.value = new Set([...open.value, db]);
});

const toggle = (name) => {
    const next = new Set(open.value);
    next.has(name) ? next.delete(name) : next.add(name);
    open.value = next;
};

const visible = computed(() => {
    const q = filter.value.trim().toLowerCase();
    if (!q) return props.databases.map((db) => ({ ...db, shown: db.collections }));
    return props.databases
        .map((db) => ({ ...db, shown: db.name.toLowerCase().includes(q) ? db.collections : db.collections.filter((c) => c.name.toLowerCase().includes(q)) }))
        .filter((db) => db.name.toLowerCase().includes(q) || db.shown.length);
});

const go = (...args) => {
    emit('go', ...args);
    emit('close-mobile');
};

const item = 'group flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition';
const idle = 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100';
const active = 'bg-emerald-500/10 font-medium text-emerald-700 dark:text-emerald-300';
</script>

<template>
    <aside
        :class="[
            'flex h-screen flex-col border-r border-slate-200 bg-white transition-[width,transform] duration-200 dark:border-slate-800 dark:bg-slate-950',
            compact ? 'w-[72px]' : 'w-[272px]',
            'max-md:fixed max-md:inset-y-0 max-md:left-0 max-md:z-50 max-md:w-[280px] max-md:shadow-2xl',
            mobileOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full',
        ]"
    >
        <div :class="['flex h-14 shrink-0 items-center border-b border-slate-200 dark:border-slate-800', compact ? 'justify-center px-2' : 'justify-between px-4']">
            <button v-if="!compact" type="button" class="flex min-w-0 items-center gap-2.5" @click="go()">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-white"><i class="bi bi-database-fill"></i></span>
                <span class="min-w-0 text-left leading-tight">
                    <span class="block text-sm font-semibold text-slate-900 dark:text-slate-100">Mongo Admin</span>
                    <span class="block truncate text-[11px] text-slate-500">{{ version ? `MongoDB ${version}` : 'MongoDB' }}</span>
                </span>
            </button>
            <button type="button" class="hidden h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 md:flex dark:hover:bg-slate-800 dark:hover:text-slate-100" :title="compact ? 'Expand sidebar' : 'Collapse sidebar'" @click="emit('toggle-collapse')">
                <i :class="['bi text-base', compact ? 'bi-layout-sidebar' : 'bi-layout-sidebar-inset']"></i>
            </button>
            <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 md:hidden dark:hover:bg-slate-800" aria-label="Close" @click="emit('close-mobile')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Collapsed rail: one icon per database -->
        <nav v-if="compact" class="flex-1 space-y-1 overflow-y-auto p-2">
            <button type="button" :class="[item, 'justify-center', !location.db ? active : idle]" title="Server" @click="go()"><i class="bi bi-hdd-rack text-base"></i></button>
            <button v-for="db in databases" :key="db.name" type="button" :class="[item, 'justify-center', location.db === db.name ? active : idle]" :title="db.name" @click="go(db.name)">
                <span class="text-xs font-semibold uppercase">{{ db.name.slice(0, 2) }}</span>
            </button>
        </nav>

        <template v-else>
            <div class="space-y-2 p-3">
                <button type="button" class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700" @click="go()">
                    <i class="bi bi-plus-lg"></i> New database
                </button>
                <div class="relative">
                    <i class="bi bi-search pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input v-model="filter" type="search" placeholder="Find database or collection" class="w-full rounded-md border-slate-200 bg-slate-50 py-1.5 pl-7 text-xs dark:border-slate-800 dark:bg-slate-900" />
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 pb-3">
                <button type="button" :class="[item, !location.db ? active : idle]" @click="go()">
                    <i class="bi bi-hdd-rack"></i> Server
                </button>
                <p class="mb-1 mt-4 px-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Databases</p>
                <div v-for="db in visible" :key="db.name">
                    <div :class="[item, 'pr-1', location.db === db.name && !location.collection ? active : idle]">
                        <button type="button" class="flex h-5 w-5 shrink-0 items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-800" :aria-label="`Toggle ${db.name}`" @click="toggle(db.name)">
                            <i class="bi text-[10px]" :class="open.has(db.name) || filter ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                        </button>
                        <button type="button" class="flex min-w-0 flex-1 items-center gap-2" @click="go(db.name)">
                            <i class="bi" :class="db.system ? 'bi-shield-lock text-amber-500' : 'bi-database'"></i>
                            <span class="truncate">{{ db.name }}</span>
                            <span class="ml-auto shrink-0 text-[10px] text-slate-400">{{ formatBytes(db.size) }}</span>
                        </button>
                    </div>
                    <div v-if="open.has(db.name) || filter" class="ml-4 border-l border-slate-200 pl-2 dark:border-slate-800">
                        <button
                            v-for="collection in db.shown"
                            :key="collection.name"
                            type="button"
                            :class="[item, 'py-1 text-[13px]', location.db === db.name && location.collection === collection.name ? active : idle]"
                            @click="go(db.name, collection.name)"
                        >
                            <i class="bi text-xs" :class="collection.type === 'view' ? 'bi-eye' : 'bi-collection'"></i>
                            <span class="truncate">{{ collection.name }}</span>
                            <span class="ml-auto shrink-0 text-[10px] tabular-nums text-slate-400">{{ formatCount(collection.count) }}</span>
                        </button>
                        <p v-if="!db.shown.length" class="px-2 py-1 text-xs text-slate-400">No collections</p>
                    </div>
                </div>
                <p v-if="!visible.length" class="px-2 py-2 text-xs text-slate-400">{{ filter ? 'Nothing matches.' : 'No databases.' }}</p>
            </nav>
        </template>

        <div :class="['shrink-0 space-y-1 border-t border-slate-200 p-3 dark:border-slate-800', compact && 'px-2']">
            <Link :href="serviceHref" :class="[item, idle, compact && 'justify-center']" title="Service & connection">
                <i class="bi bi-power"></i><span v-if="!compact">Service &amp; connection</span>
            </Link>
            <Link :href="panelHref" :class="[item, idle, compact && 'justify-center']" title="Back to dPanel">
                <i class="bi bi-box-arrow-left"></i><span v-if="!compact">Back to dPanel</span>
            </Link>
        </div>
    </aside>
</template>
