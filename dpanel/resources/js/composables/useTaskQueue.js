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
 *   const queue = useTaskQueue({ onFinished: (task) => … });
 *   queue.add({ id: data.task_id, title: domain, stage: 'queued' });
 *
 * Pass no type to watch every kind of work (the main queue window does), or a
 * type to follow one. The server decides whose tasks come back: your own in
 * any stage, plus the still-unfinished work of the users you are responsible
 * for, each carrying `user_name` and `own`.
 *
 * Polling runs every intervalMs while something is unfinished. With no active
 * task it drops to idleIntervalMs — so a window left open still picks up work
 * another user queues — or stops when that is 0.
 *
 * @param {{type?: string, onFinished?: (task: object) => void, intervalMs?: number, idleIntervalMs?: number, autoDismissMs?: number}} options
 */
export function useTaskQueue({
    type = null,
    onFinished = null,
    intervalMs = 3000,
    idleIntervalMs = 0,
    autoDismissMs = 0,
} = {}) {
    const { panelRoute, csrfHeaders } = usePanelApi();
    const tasks = ref([]);
    const activeTasks = computed(() => tasks.value.filter(isTaskActive));
    const settled = new Set();
    let timer = null;

    let delay = 0;

    const stop = () => {
        if (timer) window.clearInterval(timer);
        timer = null;
        delay = 0;
    };

    /** Run the poll on this interval; 0 stops it. */
    const every = (ms) => {
        if (ms <= 0) {
            stop();

            return;
        }
        if (timer && delay === ms) return;
        stop();
        delay = ms;
        timer = window.setInterval(poll, ms);
    };

    const start = () => every(intervalMs);

    /** Fast while work is unfinished, slow (or stopped) when nothing is. */
    const retune = () => every(activeTasks.value.length ? intervalMs : idleIntervalMs);

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

            tasks.value = incoming;

            incoming.forEach((task) => {
                const id = String(task.id);
                if (isTaskActive(task) || settled.has(id)) return;
                settled.add(id);
                if (onFinished) onFinished(task);
                // A result of your own stays a moment so it is seen, then
                // clears itself; a failure stays until it is dismissed, so the
                // message can be read.
                if (autoDismissMs > 0 && task?.own !== false && String(task.stage) === 'done') {
                    window.setTimeout(() => dismiss(id), autoDismissMs);
                }
            });

            retune();
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
    onMounted(() => poll().then(retune));
    onUnmounted(stop);

    return { tasks, activeTasks, add, dismiss, poll, start, stop };
}
