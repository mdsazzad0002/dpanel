<script setup>
import { computed } from 'vue';

// Every file in the set, grouped, with its size, where it goes and who uses it.
const props = defineProps({
    files: { type: Array, default: () => [] },
    textFiles: { type: Array, default: () => [] },
    groups: { type: Object, required: true },
    groupLabels: { type: Object, required: true },
    images: { type: Array, required: true },
    folder: { type: String, default: '' },
    sitePath: { type: Function, required: true },
    vector: { type: Boolean, default: false },
});
defineEmits(['toggle']);

const rows = computed(() => Object.keys(props.groupLabels).map((group) => ({
    group,
    label: props.groupLabels[group],
    enabled: props.groups[group],
    items: [
        ...props.images.filter((i) => i.group === group && (!i.vectorOnly || props.vector)).map((image) => ({
            ...image,
            file: props.files.find((f) => f.name === image.name) || null,
        })),
        ...props.textFiles.filter((t) => t.group === group).map((t) => ({ ...t, text: true, file: { blob: new Blob([t.content]) } })),
    ],
})));
const dims = (item) => {
    if (item.text) return 'text';
    if (item.sizes) return item.sizes.map((s) => `${s}×${s}`).join(', ');
    if (item.vectorOnly) return 'vector';
    return `${item.width}×${item.height}`;
};
const kb = (bytes) => (bytes < 1024 ? `${bytes} B` : `${(bytes / 1024).toFixed(bytes < 10240 ? 1 : 0)} KB`);
// Shown at real pixel size up to 64, so small icons can be judged as they render.
const box = (item) => (item.width && item.width <= 64 ? `${item.width}px` : null);
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-700">Size map</h2>
        <div v-for="row in rows" :key="row.group" class="border-b border-slate-100 last:border-0 dark:border-slate-700">
            <label class="flex cursor-pointer items-center gap-2 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-900/50 dark:text-slate-400">
                <input type="checkbox" :checked="row.enabled" @change="$emit('toggle', row.group)" class="rounded border-slate-300" />{{ row.label }}
            </label>
            <ul v-if="row.enabled" class="divide-y divide-slate-100 dark:divide-slate-700">
                <li v-for="item in row.items" :key="item.name" class="flex items-center gap-4 px-4 py-2.5">
                    <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg border border-slate-200 dark:border-slate-600" style="background: repeating-conic-gradient(#e2e8f0 0 25%, #fff 0 50%) 0 0 / 10px 10px">
                        <i v-if="item.text" class="bi bi-filetype-json text-xl text-slate-400"></i>
                        <img v-else-if="item.file?.url" :src="item.file.url" alt="" class="max-h-14 max-w-14 object-contain" :style="box(item) ? { width: box(item), height: box(item) } : {}" />
                        <i v-else class="bi bi-hourglass text-slate-300"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                            <span class="font-mono font-medium">{{ item.name }}</span>
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ dims(item) }}<template v-if="item.file?.blob"> · {{ kb(item.file.blob.size) }}</template></span>
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ item.use }}</p>
                        <p class="truncate font-mono text-[11px] text-slate-400">{{ sitePath(folder, item.name) }}</p>
                    </div>
                </li>
            </ul>
        </div>
    </section>
</template>
