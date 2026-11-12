<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ToolTile from './ToolTile.vue';
import { toneOf } from './toolTone';

const props = defineProps({
    section: { type: Object, required: true },
    collapsed: { type: Boolean, default: false },
    dragging: { type: Boolean, default: false },
    dropTarget: { type: Boolean, default: false },
    isFirst: { type: Boolean, default: false },
    isLast: { type: Boolean, default: false },
});

const { t } = useI18n();
const emit = defineEmits(['toggle', 'move', 'hide', 'drag-start', 'drag-end', 'drag-over', 'drop']);

// The card is only draggable while the grip is held, so text in it stays selectable
// and links inside don't start a drag.
const armed = ref(false);
const disarm = () => { armed.value = false; };
window.addEventListener('mouseup', disarm);
onBeforeUnmount(() => window.removeEventListener('mouseup', disarm));

const onDragStart = (event) => {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', props.section.id);
    emit('drag-start');
};
const onDragEnd = () => {
    armed.value = false;
    emit('drag-end');
};
</script>

<template>
    <section
        :draggable="armed"
        :class="[
            'overflow-hidden rounded-2xl border bg-white transition dark:bg-slate-900',
            dropTarget ? 'border-emerald-400 ring-2 ring-emerald-400/30' : 'border-slate-200 dark:border-white/10',
            dragging && 'opacity-40',
        ]"
        @dragstart="onDragStart"
        @dragend="onDragEnd"
        @dragover.prevent="emit('drag-over')"
        @drop.prevent="emit('drop')"
    >
        <header class="group flex items-center gap-2 px-2 py-2 sm:px-3">
            <button
                type="button"
                class="hidden h-8 w-6 shrink-0 cursor-grab items-center justify-center rounded text-slate-300 hover:text-slate-500 active:cursor-grabbing dark:text-slate-600 dark:hover:text-slate-400 sm:flex"
                :title="t('dashboard.dragToReorder')"
                :aria-label="t('dashboard.dragToReorder')"
                @mousedown="armed = true"
            >
                <i class="bi bi-grip-vertical"></i>
            </button>

            <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg py-1 pl-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400"
                :aria-expanded="!collapsed"
                @click="emit('toggle')"
            >
                <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', toneOf(section.color)]">
                    <i :class="section.iconClass"></i>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold text-slate-900 dark:text-slate-50">{{ section.label }}</span>
                    <span class="block truncate text-[11px] text-slate-500 dark:text-slate-400">{{ t('dashboard.toolCount', { count: section.items.length }, section.items.length) }}</span>
                </span>
            </button>

            <div class="flex shrink-0 items-center gap-0.5 text-slate-400 sm:opacity-0 sm:transition sm:focus-within:opacity-100 sm:group-hover:opacity-100">
                <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 disabled:hover:bg-transparent dark:hover:bg-white/[0.06] dark:hover:text-slate-200" :disabled="isFirst" :title="t('dashboard.moveUp')" :aria-label="t('dashboard.moveUp')" @click="emit('move', -1)">
                    <i class="bi bi-arrow-up"></i>
                </button>
                <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 disabled:hover:bg-transparent dark:hover:bg-white/[0.06] dark:hover:text-slate-200" :disabled="isLast" :title="t('dashboard.moveDown')" :aria-label="t('dashboard.moveDown')" @click="emit('move', 1)">
                    <i class="bi bi-arrow-down"></i>
                </button>
                <button type="button" class="hidden h-8 w-8 items-center justify-center rounded-lg hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/[0.06] dark:hover:text-slate-200 min-[400px]:flex" :title="t('dashboard.hide')" :aria-label="t('dashboard.hide')" @click="emit('hide')">
                    <i class="bi bi-eye-slash"></i>
                </button>
            </div>
            <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/[0.06] dark:hover:text-slate-200" :aria-label="collapsed ? t('dashboard.expand') : t('dashboard.collapse')" @click="emit('toggle')">
                <i :class="['bi bi-chevron-down transition-transform duration-300', collapsed && '-rotate-90']"></i>
            </button>
        </header>

        <div :class="['grid transition-[grid-template-rows] duration-300 ease-out', collapsed ? 'grid-rows-[0fr]' : 'grid-rows-[1fr]']">
            <div class="min-h-0 overflow-hidden">
                <div class="grid grid-cols-1 gap-1 border-t border-slate-100 p-2 min-[440px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 dark:border-white/[0.06]">
                    <ToolTile
                        v-for="item in section.items"
                        :key="item.label"
                        :item="item"
                        :color="section.color"
                        :tabindex="collapsed ? -1 : 0"
                    />
                </div>
            </div>
        </div>
    </section>
</template>
