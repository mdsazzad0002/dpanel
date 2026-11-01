<script setup>
import { computed, ref } from 'vue';
import { fmtBytes } from './format';

const props = defineProps({
    pulls: { type: Object, default: () => ({}) },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['pull']);

const url = ref('');
const id = ref('');

const entries = computed(() => Object.entries(props.pulls)
    .map(([name, pull]) => ({ name, ...pull }))
    .sort((a, b) => (a.state === 'downloading' ? -1 : 1) - (b.state === 'downloading' ? -1 : 1)));

const percent = (p) => (p.total_bytes ? Math.floor((p.downloaded_bytes / p.total_bytes) * 100) : null);

const submit = () => {
    if (!url.value.trim()) return;
    emit('pull', { url: url.value.trim(), id: id.value.trim() || null }, () => { url.value = ''; id.value = ''; });
};
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
        <h2 class="text-sm font-semibold">Download from a link</h2>
        <p class="mt-0.5 text-xs text-slate-500">
            Any <code>.gguf</code> file over https. On Hugging Face, use the file's <code>/resolve/main/…</code> link, not the <code>/blob/</code> page.
        </p>
        <form @submit.prevent="submit" class="mt-3 flex flex-col gap-2 md:flex-row">
            <input v-model="url" type="url" placeholder="https://huggingface.co/…/resolve/main/model-q4_k_m.gguf"
                class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
            <input v-model="id" type="text" placeholder="Name (optional)"
                class="rounded-md border border-slate-300 px-2 py-1.5 text-sm md:w-56 dark:border-slate-600 dark:bg-slate-900" />
            <button type="submit" :disabled="busy || !url.trim()"
                class="rounded-md bg-blue-600 px-4 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Download</button>
        </form>

        <div v-if="entries.length" class="mt-4 space-y-3">
            <div v-for="p in entries" :key="p.name" class="text-sm">
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate font-mono text-xs">{{ p.name }}</span>
                    <span class="shrink-0 text-xs" :class="{ 'text-red-600': p.state === 'failed', 'text-emerald-600': p.state === 'done', 'text-slate-500': p.state === 'downloading' }">
                        <template v-if="p.state === 'downloading'">
                            {{ fmtBytes(p.downloaded_bytes) }}<template v-if="p.total_bytes"> / {{ fmtBytes(p.total_bytes) }} ({{ percent(p) }}%)</template>
                        </template>
                        <template v-else-if="p.state === 'done'">Done</template>
                        <template v-else>Failed</template>
                    </span>
                </div>
                <div v-if="p.state === 'downloading'" class="mt-1 h-1.5 overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
                    <div class="h-full rounded bg-indigo-500 transition-all" :class="{ 'animate-pulse': !p.total_bytes }"
                        :style="{ width: (percent(p) ?? 100) + '%' }"></div>
                </div>
                <div v-if="p.error" class="mt-0.5 text-xs text-red-600">{{ p.error }}</div>
            </div>
        </div>
    </div>
</template>
