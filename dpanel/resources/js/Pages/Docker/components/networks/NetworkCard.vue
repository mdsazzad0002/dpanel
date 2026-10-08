<script setup>
import { computed, ref } from 'vue';

// One network: who is on it, how they reach each other, add or remove members.
const props = defineProps({
    network: { type: Object, required: true },
    containers: { type: Array, default: () => [] },
    busy: { type: String, default: '' },
});

const emit = defineEmits(['connect', 'disconnect', 'remove']);

const adding = ref('');
const aliases = ref('');
const members = computed(() => new Set(props.network.containers.map((c) => c.name)));
const candidates = computed(() => props.containers.filter((c) => !members.value.has(c.name)));
const example = computed(() => props.network.containers[0]?.name || 'my-db');

const connect = () => {
    emit('connect', props.network.name, adding.value, aliases.value.split(/[\s,]+/).filter(Boolean));
    adding.value = '';
    aliases.value = '';
};

const btn = 'rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800';
</script>

<template>
    <article class="flex flex-col rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <h3 class="truncate font-semibold">{{ network.name }}</h3>
                <div class="mt-1 flex flex-wrap gap-1 text-[11px]">
                    <span class="rounded bg-slate-100 px-1.5 dark:bg-slate-800">{{ network.driver }}</span>
                    <span v-for="s in network.subnets" :key="s" class="rounded bg-slate-100 px-1.5 font-mono dark:bg-slate-800">{{ s }}</span>
                    <span v-if="network.internal" class="rounded bg-amber-100 px-1.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">internal: no internet</span>
                    <span v-if="network.built_in" class="rounded bg-slate-100 px-1.5 text-slate-500 dark:bg-slate-800">built-in</span>
                    <span v-if="network.project" class="rounded bg-violet-100 px-1.5 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">stack {{ network.project }}</span>
                </div>
            </div>
            <button v-if="!network.built_in" type="button" :disabled="busy === `remove:${network.name}` || network.containers.length > 0" :title="network.containers.length ? 'Remove its containers from it first' : 'Remove network'" class="rounded-md px-2 py-1 text-sm text-red-600 hover:bg-red-50 disabled:opacity-30 dark:hover:bg-red-500/10" @click="emit('remove', network)">
                <i class="bi bi-trash"></i>
            </button>
        </div>

        <ul class="mt-3 flex-1 space-y-1">
            <li v-for="c in network.containers" :key="c.id" class="flex items-center justify-between gap-2 rounded-md bg-slate-50 px-2 py-1 text-sm dark:bg-slate-800/60">
                <span class="min-w-0 truncate"><i class="bi bi-box mr-1 text-slate-400"></i>{{ c.name }} <span class="font-mono text-[11px] text-slate-500">{{ c.ip }}</span></span>
                <button v-if="!['host', 'none'].includes(network.name)" type="button" :disabled="busy === `disconnect:${network.name}:${c.name}`" class="shrink-0 text-xs text-slate-500 hover:text-red-600" @click="emit('disconnect', network.name, c.name)">Leave</button>
            </li>
            <li v-if="!network.containers.length" class="text-xs text-slate-400">No containers on it.</li>
        </ul>

        <p v-if="!network.built_in && network.containers.length" class="mt-2 text-[11px] text-slate-500">
            Containers here reach each other by name, e.g. <code>http://{{ example }}:PORT</code>.
        </p>
        <p v-else-if="network.name === 'bridge'" class="mt-2 text-[11px] text-slate-500">The default network. Containers here can only reach each other by IP, not by name; create your own network for that.</p>

        <form v-if="!['host', 'none'].includes(network.name) && candidates.length" class="mt-3 flex flex-wrap gap-1.5 border-t border-slate-100 pt-3 dark:border-slate-800" @submit.prevent="connect">
            <select v-model="adding" required class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800" aria-label="Container to add">
                <option value="" disabled>Add a container…</option>
                <option v-for="c in candidates" :key="c.id" :value="c.name">{{ c.name }}</option>
            </select>
            <input v-if="!network.built_in" v-model="aliases" type="text" placeholder="extra name" class="w-24 rounded-md border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800" aria-label="Extra names" />
            <button type="submit" :disabled="!adding || busy.startsWith('connect:')" :class="btn">Add</button>
        </form>
    </article>
</template>
