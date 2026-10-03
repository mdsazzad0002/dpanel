<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import ScanProgressCard from './ScanProgressCard.vue';

const props = defineProps({
    websites: { type: Array, default: () => [] },
    scanTypes: { type: Object, default: () => ({}) },
    activeScans: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
    panelRoute: { type: Function, required: true },
});

const emit = defineEmits(['queued', 'finished', 'message']);

const target = ref(props.isAdmin ? 'server' : (props.websites[0]?.id || ''));
const scanType = ref('quick');
const starting = ref(false);

const isServerTarget = computed(() => target.value === 'server');

// "Quick (code, uploads, ...)" -> name "Quick" and what it covers.
const types = computed(() => Object.entries(props.scanTypes).map(([key, label]) => {
    const match = String(label).match(/^([^(]+)\((.+)\)\s*$/);
    return { key, name: (match ? match[1] : label).trim(), detail: match ? match[2] : '' };
}));

const alreadyRunning = computed(() => props.activeScans.some((scan) => (
    isServerTarget.value ? scan.website_id === null : scan.website_id === target.value
)));

const startScan = async () => {
    if (!target.value) return;
    starting.value = true;
    try {
        await axios.post(props.panelRoute('security.center.scans.start'), {
            website_id: isServerTarget.value ? null : target.value,
            scan_type: isServerTarget.value ? 'configuration' : scanType.value,
        });
        emit('queued');
    } catch (e) {
        emit('message', { type: 'error', text: e.response?.data?.message || 'Could not start the scan.' });
    } finally {
        starting.value = false;
    }
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold">Scan now</h2>
            <span v-if="activeScans.length" class="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                {{ activeScans.length }} running
            </span>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] lg:items-end">
            <div>
                <label for="scan-target" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Target</label>
                <select id="scan-target" v-model="target" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option v-if="isAdmin" value="server">Server configuration</option>
                    <option v-for="website in websites" :key="website.id" :value="website.id">{{ website.domain }}</option>
                </select>
            </div>

            <fieldset :disabled="isServerTarget" class="min-w-0 disabled:opacity-50">
                <legend class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Scan type</legend>
                <div v-if="!isServerTarget" class="grid grid-cols-2 gap-2 xl:grid-cols-4">
                    <label
                        v-for="type in types"
                        :key="type.key"
                        :class="scanType === type.key ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500 dark:border-blue-500 dark:bg-blue-950/40' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600'"
                        class="cursor-pointer rounded-md border px-3 py-2"
                    >
                        <input v-model="scanType" type="radio" name="scan-type" :value="type.key" class="sr-only">
                        <span class="block text-sm font-medium">{{ type.name }}</span>
                        <span class="block text-[11px] leading-tight text-slate-500 dark:text-slate-400">{{ type.detail }}</span>
                    </label>
                </div>
                <p v-else class="rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">
                    SSH, firewall, open ports, SSL and backups.
                </p>
            </fieldset>

            <button type="button" :disabled="starting || !target || alreadyRunning" class="h-10 rounded-md bg-blue-600 px-5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="startScan">
                <i class="bi bi-play-fill mr-1" aria-hidden="true" />{{ starting ? 'Starting…' : (alreadyRunning ? 'Running…' : 'Scan now') }}
            </button>
        </div>

        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
            The first scan of a website records file hashes; later scans report files that changed since then.
        </p>

        <div v-if="activeScans.length" class="mt-4 space-y-3">
            <ScanProgressCard
                v-for="scan in activeScans"
                :key="scan.id"
                :scan="scan"
                :panel-route="panelRoute"
                @finished="emit('finished', $event)"
            />
        </div>
    </section>
</template>
