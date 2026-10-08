<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    images: { type: Array, default: () => [] },
    containers: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    busy: { type: String, default: '' },
});

defineEmits(['remove']);

const search = ref('');

// An untagged image can only be named by its ID.
const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.images
        .map((image) => {
            const tagged = image.repository !== '<none>' && image.tag !== '<none>';
            const label = tagged ? `${image.repository}:${image.tag}` : image.id;
            const usedBy = props.containers.filter((c) => c.image === label || (image.tag === 'latest' && c.image === image.repository)).map((c) => c.name);
            return { ...image, label, ref: label, usedBy, key: `${image.id}-${label}` };
        })
        .filter((image) => !needle || `${image.label} ${image.id}`.toLowerCase().includes(needle));
});
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold">Images</h2>
            <input v-model="search" type="search" placeholder="Find an image" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-64 dark:border-slate-700 dark:bg-slate-800" />
        </div>

        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-3 py-2">Image</th>
                        <th class="px-3 py-2">ID</th>
                        <th class="px-3 py-2">Size</th>
                        <th class="px-3 py-2">Created</th>
                        <th class="px-3 py-2">Used by</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="image in rows" :key="image.key" class="border-t border-slate-200 dark:border-slate-800">
                        <td class="px-3 py-2 font-mono text-xs">{{ image.label }}</td>
                        <td class="px-3 py-2 font-mono text-[11px] text-slate-500">{{ image.id }}</td>
                        <td class="px-3 py-2 text-xs">{{ image.size }}</td>
                        <td class="px-3 py-2 text-xs">{{ image.created }}</td>
                        <td class="px-3 py-2 text-xs">{{ image.usedBy.join(', ') || '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <button type="button" :disabled="busy === image.id" class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-40 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950" @click="$emit('remove', image)">Remove</button>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="6" class="px-3 py-6 text-center text-sm text-slate-500">
                            {{ loading ? 'Loading…' : (search ? 'No image matches.' : 'No images yet. Pull one above.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
