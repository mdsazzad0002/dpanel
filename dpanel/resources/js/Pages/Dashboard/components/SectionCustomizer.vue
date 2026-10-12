<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({
    sections: { type: Array, required: true },
    isHidden: { type: Function, required: true },
    allCollapsed: { type: Boolean, default: false },
});

const emit = defineEmits(['set-hidden', 'collapse-all', 'reset']);

const open = ref(false);
const root = ref(null);
const onOutside = (event) => {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false;
};
onMounted(() => document.addEventListener('click', onOutside));
onBeforeUnmount(() => document.removeEventListener('click', onOutside));
</script>

<template>
    <div class="flex items-center gap-2">
        <button
            type="button"
            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-600 transition hover:border-emerald-400/50 hover:text-slate-900 dark:border-white/10 dark:bg-slate-900 dark:text-slate-300 dark:hover:text-white"
            @click="emit('collapse-all', !allCollapsed)"
        >
            <i :class="['bi', allCollapsed ? 'bi-arrows-expand' : 'bi-arrows-collapse']"></i>
            <span class="hidden min-[400px]:inline">{{ allCollapsed ? 'Expand all' : 'Collapse all' }}</span>
        </button>

        <div ref="root" class="relative">
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-gradient-to-r from-emerald-400 to-cyan-400 px-3 text-xs font-semibold text-slate-950 shadow-sm shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300"
                :aria-expanded="open"
                @click="open = !open"
            >
                <i class="bi bi-sliders"></i> Customize
            </button>

            <div
                v-if="open"
                class="absolute right-0 z-30 mt-2 w-[min(18rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl dark:border-white/10 dark:bg-slate-900"
            >
                <div class="border-b border-slate-100 px-4 py-3 dark:border-white/[0.06]">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-50">Dashboard sections</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Pick what shows. Drag cards or use the arrows to reorder.</p>
                </div>
                <ul class="max-h-80 overflow-y-auto p-1.5">
                    <li v-for="section in sections" :key="section.id">
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 hover:bg-slate-50 dark:hover:bg-white/[0.04]">
                            <input
                                type="checkbox"
                                class="rounded border-slate-300 text-emerald-500 focus:ring-emerald-400 dark:border-slate-600 dark:bg-slate-800"
                                :checked="!isHidden(section.id)"
                                @change="emit('set-hidden', section.id, !$event.target.checked)"
                            />
                            <i :class="[section.iconClass, 'text-slate-400']"></i>
                            <span class="min-w-0 flex-1 truncate text-sm text-slate-700 dark:text-slate-200">{{ section.label }}</span>
                            <span class="text-[11px] text-slate-400">{{ section.items.length }}</span>
                        </label>
                    </li>
                </ul>
                <div class="border-t border-slate-100 p-2 dark:border-white/[0.06]">
                    <button type="button" class="w-full rounded-lg px-3 py-2 text-left text-xs font-medium text-slate-500 hover:bg-slate-50 hover:text-red-600 dark:text-slate-400 dark:hover:bg-white/[0.04]" @click="emit('reset'); open = false">
                        <i class="bi bi-arrow-counterclockwise mr-1"></i> Reset layout
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
