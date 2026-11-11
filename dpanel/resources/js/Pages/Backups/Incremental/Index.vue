<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/composables/usePanelApi';

/**
 * Backups → Incremental (backlog W6): encrypted restic restore points. An
 * admin sets up the repository and keeps its key; everyone sees the restore
 * points of their own websites.
 */
const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    configured: { type: Boolean, default: false },
    keyAtRisk: { type: Boolean, default: false },
    newKey: { type: String, default: null },
    setup: { type: Object, default: null },
    websites: { type: Array, default: () => [] },
});

const { t } = useI18n();
const { panelRoute } = usePanelApi(t);
const page = usePage();
const flash = computed(() => page.props.flash || {});

const showSetup = ref(!props.configured);
const repoForm = useForm({ kind: 'local', path: '/var/backups/dpanel-restic', host: '', user: '', endpoint: '', bucket: '', remote: '', access_key: '', secret_key: '', region: '', password: '' });
const connect = () => repoForm.post(panelRoute('backups.incremental.connect'), { preserveScroll: true, onSuccess: () => { repoForm.reset('password', 'secret_key'); showSetup.value = false; } });

const confirmForm = useForm({ saved: false });
const confirmKey = () => confirmForm.post(panelRoute('backups.incremental.key.confirm'), { preserveScroll: true });

const scheduleForm = useForm({
    enabled: Boolean(props.setup?.enabled),
    schedule_time: props.setup?.schedule_time || '03:00',
    keep_daily: props.setup?.keep_daily ?? 7,
    keep_weekly: props.setup?.keep_weekly ?? 4,
    keep_monthly: props.setup?.keep_monthly ?? 6,
});
const saveSchedule = () => scheduleForm.patch(panelRoute('backups.incremental.schedule'), { preserveScroll: true });

const runForm = useForm({ website_id: null });
const run = (websiteId = null) => {
    runForm.website_id = websiteId;
    runForm.post(panelRoute('backups.incremental.run'), { preserveScroll: true });
};
const syncForm = useForm({});
const sync = () => syncForm.post(panelRoute('backups.incremental.sync'), { preserveScroll: true });

const bytes = (value) => {
    if (value === null || value === undefined) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = Number(value);
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit++; }
    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
};
const when = (value) => (value ? new Date(value).toLocaleString() : '—');
const firstError = computed(() => Object.values(repoForm.errors)[0] || Object.values(scheduleForm.errors)[0] || '');

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
const card = 'rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50';
const button = 'rounded-xl px-4 py-2 text-sm font-semibold disabled:opacity-50';
</script>

<template>
    <Head :title="t('incrementalBackups.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('incrementalBackups.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('incrementalBackups.subtitle') }} · <Link :href="route('backups.index')" class="text-blue-600 hover:underline">{{ t('incrementalBackups.fullBackups') }}</Link></p>
            </div>
        </template>

        <div class="max-w-4xl space-y-5">
            <div v-if="flash.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ flash.error }}</div>
            <div v-if="flash.success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ flash.success }}</div>
            <div v-if="firstError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ firstError }}</div>

            <!-- The key: without it the backups are unreadable, by anyone. -->
            <section v-if="keyAtRisk" class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                <h2 class="flex items-center gap-2 text-sm font-semibold"><i class="bi bi-key-fill"></i>{{ t('incrementalBackups.keyTitle') }}</h2>
                <p class="mt-1 text-sm">{{ t('incrementalBackups.keyHelp') }}</p>
                <p v-if="newKey" class="mt-3 break-all rounded-lg bg-white px-3 py-2 font-mono text-sm text-slate-900 dark:bg-slate-900 dark:text-slate-100">{{ newKey }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <a :href="panelRoute('backups.incremental.key')" class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700"><i class="bi bi-download mr-1"></i>{{ t('incrementalBackups.downloadKey') }}</a>
                    <form class="flex items-center gap-2" @submit.prevent="confirmKey">
                        <label class="flex items-center gap-2 text-sm"><input v-model="confirmForm.saved" type="checkbox" class="rounded border-amber-400" />{{ t('incrementalBackups.keySaved') }}</label>
                        <button type="submit" :disabled="!confirmForm.saved || confirmForm.processing" :class="[button, 'border border-amber-400 bg-white text-amber-800 dark:bg-transparent dark:text-amber-200']">{{ t('incrementalBackups.confirm') }}</button>
                    </form>
                </div>
            </section>

            <section v-if="isAdmin" :class="card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('incrementalBackups.repository') }}</h2>
                        <p v-if="configured" class="mt-1 break-all font-mono text-xs text-slate-500">{{ setup.repository }}</p>
                        <p v-else class="mt-1 text-xs text-slate-500">{{ t('incrementalBackups.notConfigured') }}</p>
                    </div>
                    <div v-if="configured" class="flex gap-2">
                        <a :href="panelRoute('backups.incremental.key')" :class="[button, 'border border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200']">{{ t('incrementalBackups.downloadKey') }}</a>
                        <button type="button" :disabled="syncForm.processing" :class="[button, 'border border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200']" @click="sync">{{ t('incrementalBackups.sync') }}</button>
                        <button type="button" :class="[button, 'border border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200']" @click="showSetup = !showSetup">{{ t('incrementalBackups.change') }}</button>
                    </div>
                </div>

                <form v-if="showSetup" class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="connect">
                    <label class="text-xs text-slate-500 sm:col-span-2">{{ t('incrementalBackups.kind') }}
                        <select v-model="repoForm.kind" :class="field">
                            <option value="local">{{ t('incrementalBackups.kindLocal') }}</option>
                            <option value="sftp">SFTP</option>
                            <option value="s3">{{ t('incrementalBackups.kindS3') }}</option>
                            <option value="rclone">{{ t('incrementalBackups.kindRclone') }}</option>
                        </select>
                    </label>
                    <template v-if="repoForm.kind === 'sftp'">
                        <label class="text-xs text-slate-500">{{ t('incrementalBackups.host') }}<input v-model="repoForm.host" type="text" placeholder="backup.example.com" :class="field" /></label>
                        <label class="text-xs text-slate-500">{{ t('incrementalBackups.user') }}<input v-model="repoForm.user" type="text" placeholder="backup" :class="field" /></label>
                    </template>
                    <template v-if="repoForm.kind === 's3'">
                        <label class="text-xs text-slate-500">{{ t('incrementalBackups.endpoint') }}<input v-model="repoForm.endpoint" type="text" placeholder="s3.amazonaws.com" :class="field" /></label>
                        <label class="text-xs text-slate-500">{{ t('incrementalBackups.bucket') }}<input v-model="repoForm.bucket" type="text" :class="field" /></label>
                        <label class="text-xs text-slate-500">{{ t('incrementalBackups.accessKey') }}<input v-model="repoForm.access_key" type="text" autocomplete="off" :class="field" /></label>
                        <label class="text-xs text-slate-500">{{ t('incrementalBackups.secretKey') }}<input v-model="repoForm.secret_key" type="password" autocomplete="new-password" :class="field" /></label>
                        <label class="text-xs text-slate-500">{{ t('incrementalBackups.region') }}<input v-model="repoForm.region" type="text" placeholder="us-east-1" :class="field" /></label>
                    </template>
                    <label v-if="repoForm.kind === 'rclone'" class="text-xs text-slate-500">{{ t('incrementalBackups.remote') }}<input v-model="repoForm.remote" type="text" placeholder="gdrive" :class="field" /></label>
                    <label class="text-xs text-slate-500" :class="repoForm.kind === 'local' && 'sm:col-span-2'">{{ t('incrementalBackups.path') }}<input v-model="repoForm.path" type="text" :class="field" /></label>
                    <label class="text-xs text-slate-500 sm:col-span-2">{{ t('incrementalBackups.existingPassword') }}
                        <input v-model="repoForm.password" type="password" autocomplete="new-password" :class="field" />
                        <span class="mt-1 block">{{ t('incrementalBackups.existingPasswordHelp') }}</span>
                    </label>
                    <p class="text-xs text-slate-500 sm:col-span-2">{{ t(`incrementalBackups.kindHelp.${repoForm.kind}`) }}</p>
                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <button type="submit" :disabled="repoForm.processing" :class="[button, 'bg-blue-600 text-white hover:bg-blue-700']">{{ repoForm.processing ? t('incrementalBackups.working') : (repoForm.password ? t('incrementalBackups.connect') : t('incrementalBackups.create')) }}</button>
                    </div>
                </form>
            </section>

            <section v-if="isAdmin && configured" :class="card">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('incrementalBackups.schedule') }}</h2>
                <form class="mt-3 grid gap-3 sm:grid-cols-5" @submit.prevent="saveSchedule">
                    <label class="flex items-center gap-2 text-sm sm:col-span-5"><input v-model="scheduleForm.enabled" type="checkbox" class="rounded border-slate-300" />{{ t('incrementalBackups.daily') }}</label>
                    <label class="text-xs text-slate-500">{{ t('incrementalBackups.time') }}<input v-model="scheduleForm.schedule_time" type="time" :class="field" /></label>
                    <label class="text-xs text-slate-500">{{ t('incrementalBackups.keepDaily') }}<input v-model.number="scheduleForm.keep_daily" type="number" min="0" max="366" :class="field" /></label>
                    <label class="text-xs text-slate-500">{{ t('incrementalBackups.keepWeekly') }}<input v-model.number="scheduleForm.keep_weekly" type="number" min="0" max="520" :class="field" /></label>
                    <label class="text-xs text-slate-500">{{ t('incrementalBackups.keepMonthly') }}<input v-model.number="scheduleForm.keep_monthly" type="number" min="0" max="240" :class="field" /></label>
                    <div class="flex items-end"><button type="submit" :disabled="scheduleForm.processing" :class="[button, 'w-full bg-blue-600 text-white hover:bg-blue-700']">{{ t('incrementalBackups.save') }}</button></div>
                </form>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800">
                    <span v-if="setup.last_run_at">{{ t('incrementalBackups.lastRun', { when: when(setup.last_run_at), status: setup.last_run_status }) }} {{ setup.last_run_message }}</span>
                    <span v-else>{{ t('incrementalBackups.neverRun') }}</span>
                    <button type="button" :disabled="runForm.processing" :class="[button, 'border border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200']" @click="run()">{{ t('incrementalBackups.runAll') }}</button>
                </div>
            </section>

            <section :class="card">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('incrementalBackups.restorePoints') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ t('incrementalBackups.restorePointsHelp') }}</p>
                <p v-if="!configured" class="mt-3 text-sm text-slate-500">{{ t('incrementalBackups.notConfiguredUser') }}</p>
                <div v-for="website in websites" v-else :key="website.id" class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-slate-800 dark:text-slate-100">{{ website.domain }}</p>
                        <button type="button" :disabled="runForm.processing" class="rounded-lg border border-teal-200 px-3 py-1.5 text-xs font-semibold text-teal-700 hover:bg-teal-50 disabled:opacity-50 dark:border-teal-800 dark:text-teal-300" @click="run(website.id)">{{ t('incrementalBackups.backUpNow') }}</button>
                    </div>
                    <table v-if="website.restore_points.length" class="mt-2 w-full text-left text-sm">
                        <thead class="text-xs text-slate-400">
                            <tr><th class="py-1 font-medium">{{ t('incrementalBackups.taken') }}</th><th class="py-1 font-medium">{{ t('incrementalBackups.added') }}</th><th class="py-1 font-medium">{{ t('incrementalBackups.size') }}</th><th class="py-1 font-medium">ID</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="point in website.restore_points" :key="point.id">
                                <td class="py-1.5">{{ when(point.taken_at) }} <i v-if="point.warning" class="bi bi-exclamation-triangle text-amber-500" :title="point.warning"></i></td>
                                <td class="py-1.5 text-xs">{{ bytes(point.data_added) }}</td>
                                <td class="py-1.5 text-xs">{{ bytes(point.total_bytes) }}</td>
                                <td class="py-1.5 font-mono text-xs text-slate-500">{{ point.short_id }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="mt-1 text-xs text-slate-500">{{ t('incrementalBackups.noPoints') }}</p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
