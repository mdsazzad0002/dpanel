<script setup>
import { inject, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
});

const pushToast = inject('pushToast');
const { panelRoute, requestJson } = usePanelApi();

const pythonControlLoading = ref('');
const pythonStatus = ref(null);

const pythonProcessStatusLabel = () => {
    const status = String(props.website?.python_process_status || 'pending');
    if (status === 'running') return 'Running';
    if (status === 'stopped') return 'Stopped';
    if (status === 'error') return 'Error';
    return 'Starting…';
};

const pythonProcessStatusClass = () => {
    const status = String(props.website?.python_process_status || 'pending');
    if (status === 'running') return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
    if (status === 'stopped') return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
    if (status === 'error') return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
};

const controlPythonProcess = async (action) => {
    if (pythonControlLoading.value) return;
    pythonControlLoading.value = action;

    try {
        const data = await requestJson(panelRoute('websites.python.control', { id: props.website.id }), {
            body: { action },
        });
        if (!data.success) throw new Error(data.message || 'Python process action failed.');

        if (action === 'status') {
            pythonStatus.value = data.data || null;
        } else {
            pushToast?.(data.message || 'Python process updated successfully.', 'success');
            router.reload({ only: ['website'], preserveScroll: true });
        }
    } catch (error) {
        pushToast?.(error?.message || 'Python process action failed.', 'error');
    } finally {
        pythonControlLoading.value = '';
    }
};
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <i class="bi bi-filetype-py text-base text-emerald-600 dark:text-emerald-400"></i>
                    Python Service
                </h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ website.python_start_command || `gunicorn ${website.python_entry_file || 'app:app'}` }}
                    · port {{ website.python_port || '-' }}
                    · Python {{ website.python_version || 'default' }}
                </p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium" :class="pythonProcessStatusClass()">
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ pythonProcessStatusLabel() }}
            </span>
        </div>

        <div v-if="pythonStatus" class="mt-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
            systemd: {{ pythonStatus.active_state || 'unknown' }} · listening: {{ pythonStatus.listening ? 'yes' : 'no' }}
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" :disabled="Boolean(pythonControlLoading)"
                class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2 text-xs font-medium text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400"
                @click="controlPythonProcess('start')">
                <i class="bi bi-play-fill"></i>
                {{ pythonControlLoading === 'start' ? 'Starting…' : 'Start' }}
            </button>
            <button type="button" :disabled="Boolean(pythonControlLoading)"
                class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2 text-xs font-medium text-amber-700 transition hover:border-amber-300 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400"
                @click="controlPythonProcess('restart')">
                <i class="bi bi-arrow-clockwise"></i>
                {{ pythonControlLoading === 'restart' ? 'Restarting…' : 'Restart' }}
            </button>
            <button type="button" :disabled="Boolean(pythonControlLoading)"
                class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3.5 py-2 text-xs font-medium text-red-700 transition hover:border-red-300 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400"
                @click="controlPythonProcess('stop')">
                <i class="bi bi-stop-fill"></i>
                {{ pythonControlLoading === 'stop' ? 'Stopping…' : 'Stop' }}
            </button>
            <button type="button" :disabled="Boolean(pythonControlLoading)"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                @click="controlPythonProcess('status')">
                <i class="bi bi-arrow-repeat"></i>
                {{ pythonControlLoading === 'status' ? 'Checking…' : 'Refresh Status' }}
            </button>
        </div>
        <p class="mt-3 text-xs text-slate-400 dark:text-slate-500">
            dPanel creates a virtualenv and installs <code>requirements.txt</code> automatically on first start/restart, then runs your app with gunicorn.
        </p>
    </section>
</template>
