<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    historyEntries: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: 'SQL History',
    },
});

const emit = defineEmits(['use-entry']);

const HISTORY_HEIGHT_KEY = 'serverpanel-phpmyadmin-sql-history-height';
const HISTORY_MIN = 0;
const HISTORY_MAX = 200;
const HISTORY_DEFAULT = 0;
const HISTORY_OPEN = 200;

const footerShell = ref(null);
const historyHeight = ref(HISTORY_DEFAULT);
const resizing = ref(false);

const clamp = (value) => Math.min(HISTORY_MAX, Math.max(HISTORY_MIN, Number(value) || 0));

const applyHistoryHeight = (value) => {
    historyHeight.value = clamp(value);

    if (typeof document !== 'undefined') {
        document.documentElement.style.setProperty('--phpmyadmin-sql-history-height', `${historyHeight.value}px`);
    }
};

const loadHistoryHeight = () => {
    if (typeof window === 'undefined') return;

    try {
        const saved = Number(window.localStorage.getItem(HISTORY_HEIGHT_KEY));
        if (Number.isFinite(saved)) {
            applyHistoryHeight(saved);
            return;
        }
    } catch {
        // Ignore storage failures.
    }

    applyHistoryHeight(HISTORY_DEFAULT);
};

const saveHistoryHeight = () => {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(HISTORY_HEIGHT_KEY, String(historyHeight.value));
    } catch {
        // Ignore storage failures.
    }
};

const toggleHistory = () => {
    applyHistoryHeight(historyHeight.value <= 50 ? HISTORY_OPEN : 0);
};

const stopResize = () => {
    if (!resizing.value) return;

    resizing.value = false;
    if (typeof window === 'undefined') return;

    window.removeEventListener('pointermove', handleResizeMove);
    window.removeEventListener('pointerup', stopResize);
    window.removeEventListener('pointercancel', stopResize);
};

function handleResizeMove(event) {
    if (!footerShell.value) return;

    const rect = footerShell.value.getBoundingClientRect();
    applyHistoryHeight(rect.bottom - event.clientY);
}

const startResize = (event) => {
    event.preventDefault();
    resizing.value = true;
    handleResizeMove(event);
    window.addEventListener('pointermove', handleResizeMove);
    window.addEventListener('pointerup', stopResize);
    window.addEventListener('pointercancel', stopResize);
};

const handleUseEntry = (entry) => {
    emit('use-entry', entry);
};

watch(historyHeight, () => {
    saveHistoryHeight();
}, { immediate: false });

loadHistoryHeight();

onBeforeUnmount(() => {
    stopResize();
});
</script>

<template>
    <section
        ref="footerShell"
        class="fixed inset-x-0 bottom-0 z-40 overflow-visible border-t border-slate-200 bg-white/98 shadow-[0_-12px_30px_rgba(15,23,42,0.08)] backdrop-blur dark:border-slate-800 dark:bg-slate-950/95"
        :style="{ height: `${historyHeight}px` }"
    >
        <div class="mx-auto flex w-full  flex-col ">
            <div class="relative border-b border-slate-200 px-0  dark:border-slate-800">
                <button
                    type="button"
                    class="absolute -top-5 right-0 inline-flex items-center gap-1 border border-slate-300 bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-700 shadow-sm transition hover:bg-slate-200 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                    @click="toggleHistory"
                >
                    <i class="bi bi-terminal text-[11px]"></i>
                    <span>{{ historyHeight === 0 ? 'Open history' : 'Hide history' }}</span>
                </button>
                <div
                    class="flex h-3 cursor-row-resize items-center justify-center"
                    @pointerdown="startResize"
                    @dblclick="toggleHistory"
                >
                    <span class="h-1 w-12 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                </div>


            </div>

            <div v-show="historyHeight > 0" class="absolute h-[calc(100%-1rem)] left-0 right-0 top-[1rem] w-full  overflow-auto px-3 pt-0 pb-3">
            <button v-for="entry in historyEntries" :key="`${entry.created_at}-${entry.sql}`" type="button" class="w-full   bg-slate-50  text-left border-b  border-slate-700 text-slate-700 transition hover:border-cyan-300 hover:bg-cyan-50 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200 dark:hover:border-cyan-700 dark:hover:bg-slate-900" @click="handleUseEntry(entry)">

                <div class="flex items-center justify-between ">
                    <span class="truncate font-medium">{{ entry.label }} ( <span class="truncate">{{ entry.database || 'global' }}</span>)</span>
                    <span class="shrink-0  bg-cyan-100  text-[10px] font-semibold uppercase text-cyan-700 dark:bg-cyan-950/50 dark:text-cyan-300">
                        {{ entry.mode }} ( <span v-if="entry.duration_ms !== null">{{ entry.duration_ms }} ms</span>)
                    </span>
                </div>

            </button>

            <div
                v-if="historyEntries.length === 0"
                class="rounded-xl border border-dashed border-slate-300 px-3 py-4 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400"
            >
                No history yet.
            </div>
            </div>
        </div>
    </section>
</template>
