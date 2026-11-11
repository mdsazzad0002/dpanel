<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/composables/usePanelApi';

/**
 * Backlog W8: the restore test report. Weekly, after that day's backup, the
 * repository is checked and every website's newest restore point is read
 * back; each run stays here as a report.
 */
const props = defineProps({
    report: { type: Object, required: true },
    backupScheduled: { type: Boolean, default: false },
});

const { t } = useI18n();
const { panelRoute } = usePanelApi(t);

const form = useForm({
    restore_test_enabled: Boolean(props.report.enabled),
    restore_test_day: props.report.day ?? 0,
    restore_test_percent: props.report.percent ?? 5,
    restore_test_sample: props.report.sample ?? 20,
});
const save = () => form.patch(panelRoute('backups.incremental.test.settings'), { preserveScroll: true });

const testForm = useForm({});
const testNow = () => testForm.post(panelRoute('backups.incremental.test'), { preserveScroll: true });

const open = ref(props.report.runs[0]?.status === 'failed' ? props.report.runs[0].id : null);
const toggle = (id) => { open.value = open.value === id ? null : id; };

const running = computed(() => props.report.runs.some((run) => run.status === 'running'));
let timer = null;
const poll = (on) => {
    clearInterval(timer);
    timer = on ? setInterval(() => router.reload({ only: ['restoreTest'], preserveScroll: true }), 10000) : null;
};
watch(running, poll, { immediate: true });
onBeforeUnmount(() => clearInterval(timer));

// No pass for two weeks while there is something to test: say so loudly.
const overdue = computed(() => {
    if (!props.report.last_passed_at) return props.report.runs.length > 0;
    return Date.now() - new Date(props.report.last_passed_at).getTime() > 14 * 86400000;
});

const when = (value) => (value ? new Date(value).toLocaleString() : '—');
const bytes = (value) => {
    if (value === null || value === undefined) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = Number(value);
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit++; }
    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
};
const badge = (status) => ({
    passed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    running: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
}[status] || 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300');

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
const button = 'rounded-xl px-4 py-2 text-sm font-semibold disabled:opacity-50';
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('restoreTest.title') }}</h2>
                <p class="mt-1 max-w-2xl text-xs text-slate-500">{{ t('restoreTest.help') }}</p>
            </div>
            <button type="button" :disabled="testForm.processing || running" :class="[button, 'border border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200']" @click="testNow">
                {{ running ? t('restoreTest.running') : t('restoreTest.testNow') }}
            </button>
        </div>

        <p v-if="overdue" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            <i class="bi bi-exclamation-triangle mr-1"></i>{{ report.last_passed_at ? t('restoreTest.overdue', { when: when(report.last_passed_at) }) : t('restoreTest.neverPassed') }}
        </p>
        <p v-else-if="report.last_passed_at" class="mt-3 text-xs text-emerald-700 dark:text-emerald-400"><i class="bi bi-check-circle mr-1"></i>{{ t('restoreTest.lastPassed', { when: when(report.last_passed_at) }) }}</p>

        <form class="mt-4 grid gap-3 sm:grid-cols-4" @submit.prevent="save">
            <label class="flex items-center gap-2 text-sm sm:col-span-4"><input v-model="form.restore_test_enabled" type="checkbox" class="rounded border-slate-300" />{{ t('restoreTest.weekly') }}</label>
            <label class="text-xs text-slate-500">{{ t('restoreTest.day') }}
                <select v-model.number="form.restore_test_day" :class="field">
                    <option v-for="day in 7" :key="day" :value="day - 1">{{ t(`restoreTest.days.${day - 1}`) }}</option>
                </select>
            </label>
            <label class="text-xs text-slate-500">{{ t('restoreTest.percent') }}<input v-model.number="form.restore_test_percent" type="number" min="0" max="100" :class="field" /></label>
            <label class="text-xs text-slate-500">{{ t('restoreTest.sample') }}<input v-model.number="form.restore_test_sample" type="number" min="1" max="100" :class="field" /></label>
            <div class="flex items-end"><button type="submit" :disabled="form.processing" :class="[button, 'w-full bg-blue-600 text-white hover:bg-blue-700']">{{ t('incrementalBackups.save') }}</button></div>
            <p class="text-xs text-slate-500 sm:col-span-4">{{ t('restoreTest.settingsHelp') }}</p>
            <p v-if="form.restore_test_enabled && !backupScheduled" class="text-xs text-amber-700 dark:text-amber-400 sm:col-span-4">{{ t('restoreTest.needsSchedule') }}</p>
            <p v-for="error in Object.values(form.errors)" :key="error" class="text-xs text-red-600 sm:col-span-4">{{ error }}</p>
        </form>

        <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
            <p v-if="!report.runs.length" class="text-sm text-slate-500">{{ t('restoreTest.noRuns') }}</p>
            <div v-for="run in report.runs" :key="run.id" class="border-b border-slate-100 py-2 last:border-0 dark:border-slate-800">
                <button type="button" class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 text-left text-sm" @click="toggle(run.id)">
                    <i :class="['bi text-slate-400', open === run.id ? 'bi-chevron-down' : 'bi-chevron-right']"></i>
                    <span class="font-medium text-slate-800 dark:text-slate-100">{{ when(run.started_at) }}</span>
                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', badge(run.status)]">{{ t(`restoreTest.status.${run.status}`) }}</span>
                    <span class="text-xs text-slate-500">{{ t('restoreTest.summary', { tested: run.websites_tested, failed: run.websites_failed }) }}</span>
                    <span class="text-xs text-slate-400">{{ t(`restoreTest.trigger.${run.trigger}`) }}</span>
                </button>
                <div v-if="open === run.id" class="mt-2 space-y-2 pl-6 text-sm">
                    <p v-if="run.message" class="text-xs text-slate-600 dark:text-slate-300">{{ run.message }}</p>
                    <p class="text-xs" :class="run.repository_healthy === false ? 'text-red-600 dark:text-red-400' : 'text-slate-500'">
                        <i :class="['bi mr-1', run.repository_healthy === false ? 'bi-x-circle' : 'bi-shield-check']"></i>
                        {{ t('restoreTest.repositoryCheck', { percent: run.read_data_percent }) }}
                        <template v-if="run.repository_healthy !== null">— {{ run.repository_message }}</template>
                    </p>
                    <table v-if="run.results.length" class="w-full text-left text-xs">
                        <thead class="text-slate-400">
                            <tr><th class="py-1 font-medium">{{ t('restoreTest.website') }}</th><th class="py-1 font-medium">{{ t('restoreTest.point') }}</th><th class="py-1 font-medium">{{ t('restoreTest.filesRead') }}</th><th class="py-1 font-medium">{{ t('restoreTest.databases') }}</th><th class="py-1 font-medium">{{ t('restoreTest.result') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="result in run.results" :key="result.website_id" class="align-top">
                                <td class="py-1.5 font-medium text-slate-700 dark:text-slate-200">{{ result.domain }}</td>
                                <td class="py-1.5 text-slate-500"><span class="font-mono">{{ result.snapshot_id ? result.snapshot_id.slice(0, 8) : '—' }}</span><span v-if="result.taken_at" class="block">{{ when(result.taken_at) }}</span></td>
                                <td class="py-1.5 text-slate-500"><template v-if="result.files !== undefined">{{ t('restoreTest.filesOf', { read: result.files_read, total: result.files }) }} · {{ bytes(result.bytes) }}</template><template v-else>—</template></td>
                                <td class="py-1.5 text-slate-500">
                                    <span v-for="database in result.databases || []" :key="database.name" class="mr-2 whitespace-nowrap"><i :class="['bi', database.ok ? 'bi-check text-emerald-600' : 'bi-x text-red-600']"></i>{{ database.name }}</span>
                                    <template v-if="!(result.databases || []).length">—</template>
                                </td>
                                <td class="py-1.5">
                                    <span v-if="result.ok" class="text-emerald-700 dark:text-emerald-400"><i class="bi bi-check-circle mr-1"></i>{{ t('restoreTest.readBack') }}</span>
                                    <span v-else class="text-red-600 dark:text-red-400"><i class="bi bi-x-circle mr-1"></i>{{ t(`restoreTest.problem.${result.problem || 'damaged'}`) }}<span class="block break-all text-slate-500">{{ result.error }}</span></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>
