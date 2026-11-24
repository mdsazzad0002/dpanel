import { computed, onMounted, onUnmounted, ref } from 'vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const ACTIVE_STAGES = ['queued', 'running'];

export const isTaskActive = (task) => ACTIVE_STAGES.includes(String(task?.stage || ''));

/**
 * The panel side of App\Services\Tasks\BackgroundTaskQueue: polls the queued
 * background work of the signed-in user and hands it to FloatingTaskQueue.vue.
 *
 * Any page that queues such work uses this; nothing here knows what the work
 * is. A page that needs to react when a task ends passes onFinished, which is
 * called once per task as it reaches 'done' or 'failed' (also for a task this
 * page did not start, so a second tab or a reload still settles up).
 *
 *   const queue = useTaskQueue({ type: 'website_delete', onFinished: (task) => … });
 *   queue.add({ id: data.task_id, title: domain, stage: 'queued' });
 *
 * @param {{type?: string, onFinished?: (task: object) => void, intervalMs?: number}} options
 */
export function useTaskQueue({ type = null, onFinished = null, intervalMs = 3000 } = {}) {
    const { panelRoute, csrfHeaders } = usePanelApi();
    const tasks = ref([]);
    const activeTasks = computed(() => tasks.value.filter(isTaskActive));
    const settled = new Set();
    let timer = null;

    const stop = () => {
        if (timer) window.clearInterval(timer);
        timer = null;
    };

    const start = () => {
        if (!timer) timer = window.setInterval(poll, intervalMs);
    };

    const poll = async () => {
        try {
            const url = panelRoute('tasks.queue.status') + (type ? `?type=${encodeURIComponent(type)}` : '');
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return;
            const data = await response.json().catch(() => ({}));
            const incoming = Array.isArray(data.tasks) ? data.tasks : [];

            incoming.forEach((task) => {
                const id = String(task.id);
                if (isTaskActive(task) || settled.has(id)) return;
                settled.add(id);
                if (onFinished) onFinished(task);
            });

            tasks.value = incoming;
            if (activeTasks.value.length === 0) stop();
        } catch (error) {
            // A failed poll is simply retried on the next tick.
        }
    };

    /** Show a task the page just queued, before the first poll comes back. */
    const add = (task) => {
        const id = String(task?.id || '');
        if (!id || tasks.value.some((item) => String(item.id) === id)) return;
        tasks.value = [...tasks.value, { stage: 'queued', message: '', ...task, id }];
        start();
    };

    /** Drop a finished card: locally at once, then on the server. */
    const dismiss = async (taskId) => {
        const id = String(taskId);
        tasks.value = tasks.value.filter((item) => String(item.id) !== id);
        try {
            await fetch(panelRoute('tasks.queue.dismiss', { taskId: id }), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
            });
        } catch (error) {
            // Nothing to do: the entry expires on its own.
        }
    };

    // Cards are picked back up after a reload, or when another tab queued them.
    onMounted(() => poll().then(() => { if (activeTasks.value.length) start(); }));
    onUnmounted(stop);

    return { tasks, activeTasks, add, dismiss, poll, start, stop };
}
