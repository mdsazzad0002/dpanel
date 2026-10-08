<script setup>
import { computed, ref } from 'vue';

// Images on the server, which containers use them, and quick ways on from
// each: run a container from it, pull it again for a newer build, remove it.
const props = defineProps({
    images: { type: Array, default: () => [] },
    containers: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    busy: { type: String, default: '' },
});

const emit = defineEmits(['remove', 'run', 'pull']);

const search = ref('');
const filter = ref('all');

// An untagged image can only be named by its ID.
const all = computed(() => props.images.map((image) => {
    const tagged = image.repository !== '<none>' && image.tag !== '<none>';
    const label = tagged ? `${image.repository}:${image.tag}` : image.id;
    const usedBy = props.containers
        .filter((c) => c.image === label || (image.tag === 'latest' && c.image === image.repository) || c.image === image.id)
        .map((c) => c.name);
    return { ...image, tagged, label, ref: label, usedBy, key: `${image.id}-${label}` };
}));

const filters = computed(() => [
    { key: 'all', label: 'All', count: all.value.length },
    { key: 'used', label: 'In use', count: all.value.filter((i) => i.usedBy.length).length },
    { key: 'unused', label: 'Unused', count: all.value.filter((i) => !i.usedBy.length).length },
    { key: 'dangling', label: 'Untagged', count: all.value.filter((i) => !i.tagged).length },
].filter((f) => f.key === 'all' || f.count > 0));

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return all.value
        .filter((i) => ({ all: true, used: i.usedBy.length > 0, unused: !i.usedBy.length, dangling: !i.tagged })[filter.value] ?? true)
        .filter((i) => !needle || `${i.label} ${i.id}`.toLowerCase().includes(needle));
});

const btn = 'inline-flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800';
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4 dark:border-slate-800">
            <div class="flex flex-wrap gap-1">
                <button v-for="f in filters" :key="f.key" type="button" class="rounded-full px-3 py-1 text-xs font-medium" :class="filter === f.key ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'" @click="filter = f.key">
                    {{ f.label }} <span class="opacity-60">{{ f.count }}</span>
                </button>
            </div>
            <input v-model="search" data-docker-search type="search" placeholder="Find an image  ( / )" class="ml-auto w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm sm:w-64 dark:border-slate-700 dark:bg-slate-800" />
        </div>

        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="image in rows" :key="image.key" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <div class="min-w-0 flex-1 basis-60">
                    <div class="truncate font-mono text-sm" :title="image.label">{{ image.label }}</div>
                    <div class="mt-0.5 text-xs text-slate-500">
                        <span class="font-mono">{{ image.id }}</span> · {{ image.size }} · {{ image.created }}
                    </div>
                </div>
                <div class="min-w-0 basis-40 text-xs">
                    <span v-if="image.usedBy.length" class="text-slate-600 dark:text-slate-300"><i class="bi bi-box mr-1"></i>{{ image.usedBy.join(', ') }}</span>
                    <span v-else class="text-slate-400">not used</span>
                </div>
                <div class="flex flex-wrap gap-1">
                    <button v-if="image.tagged" type="button" :class="btn" title="Run a container from this image" @click="emit('run', image)"><i class="bi bi-play-fill"></i>Run</button>
                    <button v-if="image.tagged" type="button" :class="btn" :disabled="busy === `pull:${image.label}`" title="Pull again for a newer build of this tag" @click="emit('pull', image.label)">
                        <i class="bi bi-cloud-arrow-down" :class="busy === `pull:${image.label}` ? 'animate-pulse' : ''"></i>{{ busy === `pull:${image.label}` ? 'Pulling…' : 'Pull again' }}
                    </button>
                    <button type="button" :disabled="busy === image.id" :class="[btn, 'text-red-700 dark:text-red-300']" :title="image.usedBy.length ? 'Still used by a container' : 'Remove'" @click="emit('remove', image, image.usedBy.length > 0)">
                        <i class="bi bi-trash"></i>Remove
                    </button>
                </div>
            </li>
            <li v-if="!rows.length" class="px-4 py-10 text-center text-sm text-slate-500">
                {{ loading ? 'Loading…' : (search || filter !== 'all' ? 'No image matches.' : 'No images yet. Pull one above.') }}
            </li>
        </ul>
    </section>
</template>
