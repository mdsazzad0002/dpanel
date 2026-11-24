<script setup>
// The panel's floating task card: one card per queued background task, stacked
// bottom right over the page, fed by useTaskQueue.js (App\Services\Tasks\
// BackgroundTaskQueue on the server).
//
// Nothing here knows what a task does. A card shows title, stage and message
// from the task itself, and its wording comes from tasks.types.<type>.<stage>
// in the locale files, falling back to the generic stage labels — so another
// kind of background work only needs its own keys, and optionally the
// "task-actions" slot for a button of its own (open a file, retry, …).
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    tasks: { type: Array, default: () => [] },
    /** Header text; the generic "Background tasks" by default. */
    title: { type: String, default: '' },
});
const emit = defineEmits(['dismiss']);
const { t, te } = useI18n();

const collapsed = ref(false);

const STAGE_STYLES = {
    // Written out in full: Tailwind only ships class names it can see here.
    queued: { icon: 'bi-hourglass-split', tone: 'text-slate-500 dark:text-slate-400', edge: 'border-l-slate-300 dark:border-l-slate-600', bar: 'w-1/5 bg-slate-400' },
    running: { icon: 'bi-arrow-repeat animate-spin', tone: 'text-indigo-600 dark:text-indigo-400', edge: 'border-l-indigo-500 dark:border-l-indigo-400', bar: 'w-2/3 animate-pulse bg-indigo-500' },
    done: { icon: 'bi-check-circle-fill', tone: 'text-emerald-600 dark:text-emerald-400', edge: 'border-l-emerald-500 dark:border-l-emerald-400', bar: '' },
    failed: { icon: 'bi-exclamation-triangle-fill', tone: 'text-red-600 dark:text-red-400', edge: 'border-l-red-500 dark:border-l-red-400', bar: '' },
};

// tasks.types.<type>.<stage> when the task type has its own wording, else the
// generic stage label.
const stageLabel = (task) => {
    const key = `tasks.types.${task.type}.${task.stage}`;

    return te(key) ? t(key) : t(`tasks.queue.stage.${task.stage}`);
};

const items = computed(() => props.tasks.map((task) => {
    const stage = STAGE_STYLES[String(task?.stage || '')] ? String(task.stage) : 'queued';

    return {
        ...task,
        stage,
        style: STAGE_STYLES[stage],
        finished: stage === 'done' || stage === 'failed',
        label: stageLabel({ ...task, stage }),
    };
}));

const activeCount = computed(() => items.value.filter((item) => !item.finished).length);
const finishedItems = computed(() => items.value.filter((item) => item.finished));
</script>

<template>
    <section
        v-if="items.length"
        class="pointer-events-auto flex w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"
    >
        <header class="flex items-center gap-2 border-b border-slate-200 px-3 py-2 dark:border-slate-700">
            <i class="bi bi-list-task text-slate-500 dark:text-slate-400"></i>
            <p class="min-w-0 flex-1 truncate text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                {{ title || t('tasks.queue.title') }}
                <span v-if="activeCount">· {{ t('tasks.queue.active', { n: activeCount }) }}</span>
            </p>
            <button
                v-if="finishedItems.length"
                type="button"
                class="rounded-md px-1.5 py-0.5 text-[11px] font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                @click="finishedItems.forEach((item) => emit('dismiss', item.id))"
            >
                {{ t('tasks.queue.clearFinished') }}
            </button>
            <button
                type="button"
                class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
                :title="collapsed ? t('tasks.queue.expand') : t('tasks.queue.collapse')"
                @click="collapsed = !collapsed"
            >
                <i class="bi text-xs" :class="collapsed ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
            </button>
        </header>

        <div v-show="!collapsed" class="max-h-[60vh] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
            <article v-for="item in items" :key="item.id" class="border-l-4 px-3 py-2.5" :class="item.style.edge">
                <div class="flex items-start gap-2">
                    <i class="bi mt-0.5 text-base" :class="[item.style.icon, item.style.tone]"></i>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ item.title || item.id }}</p>
                        <p class="mt-0.5 text-xs" :class="item.style.tone">
                            {{ item.label }}
                            <span v-if="item.subtitle" class="text-slate-500 dark:text-slate-400">· {{ item.subtitle }}</span>
                        </p>
                        <p
                            v-if="item.message"
                            class="mt-1 break-words text-xs"
                            :class="item.stage === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'"
                        >
                            {{ item.message }}
                        </p>
                        <!-- Room for a task type's own buttons (download, retry, open). -->
                        <div v-if="item.finished" class="mt-1.5 empty:hidden">
                            <slot name="task-actions" :task="item"></slot>
                        </div>
                    </div>
                    <button
                        v-if="item.finished"
                        type="button"
                        class="shrink-0 rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
                        :title="t('tasks.queue.dismiss')"
                        @click="emit('dismiss', item.id)"
                    >
                        <i class="bi bi-x-lg text-xs"></i>
                    </button>
                </div>

                <div v-if="!item.finished" class="mt-2 h-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                    <div class="h-full rounded-full transition-all" :class="item.style.bar"></div>
                </div>
            </article>
        </div>
    </section>
</template>
