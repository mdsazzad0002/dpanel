<script setup>
import { ref } from 'vue';

const props = defineProps({
    entries: { type: Array, default: () => [] },
    busy: { type: String, default: '' },
});

const emit = defineEmits(['add', 'remove']);

const ip = ref('');

const add = () => {
    const value = ip.value.trim();
    if (!value) return;
    emit('add', value);
    ip.value = '';
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-semibold">Whitelist</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">These IPs are never blocked, whatever they do. Use it for your office IP or monitoring servers.</p>

        <form class="mt-4 grid gap-3 md:grid-cols-[1fr_auto]" @submit.prevent="add">
            <input v-model="ip" type="text" placeholder="203.0.113.10 or 203.0.113.0/24" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <button type="submit" :disabled="!ip.trim() || busy === ip.trim()" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40">
                Add to whitelist
            </button>
        </form>

        <ul class="mt-4 divide-y divide-slate-200 rounded-md border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
            <li v-for="entry in props.entries" :key="entry" class="flex items-center justify-between px-3 py-2">
                <span class="font-mono text-xs">{{ entry }}</span>
                <button type="button" :disabled="busy === entry" class="rounded border border-red-300 px-2.5 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-60 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20" @click="emit('remove', entry)">
                    {{ busy === entry ? 'Removing…' : 'Remove' }}
                </button>
            </li>
            <li v-if="props.entries.length === 0" class="px-3 py-3 text-center text-sm text-slate-500">No IPs whitelisted. Localhost is always allowed.</li>
        </ul>
    </section>
</template>
