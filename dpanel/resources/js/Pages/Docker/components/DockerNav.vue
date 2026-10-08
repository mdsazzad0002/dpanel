<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { sections } from '../composables/sections';

// Every Docker section one tap away, on phones too (the row scrolls sideways).

const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const href = (name) => (token.value ? route(name, { token: token.value }) : route(name));
const active = (name) => {
    try {
        return route().current(name);
    } catch {
        return false;
    }
};
</script>

<template>
    <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1" aria-label="Docker sections">
        <Link
            v-for="section in sections"
            :key="section.route"
            :href="href(section.route)"
            :class="active(section.route)
                ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:border-indigo-400 dark:bg-indigo-500/10 dark:text-indigo-300'
                : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'"
            class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-medium"
            :title="`Shortcut: g then ${section.key}`"
        >
            <i :class="section.icon"></i>{{ section.label }}
        </Link>
    </nav>
</template>
