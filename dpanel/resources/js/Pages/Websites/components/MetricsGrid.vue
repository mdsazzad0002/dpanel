<script setup>
import { computed, inject, ref } from 'vue';
import { Deferred } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    metrics: {
        type: Object,
        default: () => ({}),
    },
    rootInspection: {
        type: Object,
        default: () => ({}),
    },
});

const pushToast = inject('pushToast');
const { panelRoute, requestJson } = usePanelApi();

const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const metrics = computed(() => [
    { label: 'Connections', value: toNumber(props.metrics.connections_current), icon: 'bi-people', color: 'blue' },
    { label: 'Active Jobs', value: toNumber(props.metrics.jobs_pending), icon: 'bi-list-task', color: 'amber' },
    { label: 'Databases', value: toNumber(props.metrics.databases_count), icon: 'bi-database', color: 'violet' },
    { label: 'Disk Usage', value: `${toNumber(props.metrics.disk_used_mb).toFixed(1)} MB`, icon: 'bi-hdd', color: 'emerald', sub: `Files: ${toNumber(props.metrics.file_count)}` },
]);

const metricColorClasses = {
    blue: 'bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
    amber: 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
    violet: 'bg-violet-500/10 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
};

// Sites under /var/www get the whole /var/www; everywhere else the project
// root (the directory holding .git when there is one).
const permissionRoot = computed(() => {
    const siteRoot = String(props.website?.root_path || '').trim().replace(/\/+$/, '');
    if (siteRoot === '/var/www' || siteRoot.startsWith('/var/www/')) return '/var/www';
    const projectRoot = props.rootInspection?.has_git ? String(props.rootInspection?.root_path || '').trim() : '';
    return (projectRoot || siteRoot).replace(/\/+$/, '');
});

const localDevPermissionCommand = computed(() => {
    const path = permissionRoot.value;
    if (!path) return '';
    return `sudo chmod -R u+rwX ${path} && sudo chmod -R 777 ${path}`;
});

// After chmod -R 777 every file shows up as modified in git; tell git to
// ignore permission bits. Only offered when the project is a git checkout.
const gitFileModeCommand = computed(() => {
    if (!props.rootInspection?.has_git) return '';
    const path = String(props.rootInspection?.root_path || props.website?.root_path || '').trim();
    return path ? `git -C ${path} config core.fileMode false` : '';
});

const gitSafeDirectoryCommand = computed(() => {
    if (!props.rootInspection?.has_git) return '';
    const path = String(props.rootInspection?.root_path || props.website?.root_path || '').trim();
    return path ? `git config --global --add safe.directory ${path}` : '';
});

// The panel can only run these for a website account under /home; the
// /var/www system site keeps the copy-and-run commands only.
const canRunLocalDevActions = computed(() => String(props.website?.root_path || '').startsWith('/home/'));
const hasGit = computed(() => Boolean(props.rootInspection?.has_git));
const showCommands = ref(false);
const localDevLoading = ref('');

const localDevActions = computed(() => [
    { key: 'trust', label: 'Trust Repo', icon: 'bi-shield-check', needsGit: true, hint: 'Mark the Git repository as safe for every user, so VS Code doesn\'t ask to trust it.' },
    { key: 'open_permissions', label: 'Permission All', icon: 'bi-unlock', needsGit: false, hint: 'chmod -R 777 the project so your own user can edit every file.' },
    { key: 'ignore_file_mode', label: 'Ignore Mode', icon: 'bi-git', needsGit: true, hint: 'git config core.fileMode false — permission changes stop showing as modified files.' },
]);

const runLocalDevAction = async (action) => {
    if (localDevLoading.value) return;
    if (action.key === 'open_permissions'
        && !window.confirm(`Set permissions of ${permissionRoot.value} to 777? Only do this on your local machine — never on a production server.`)) return;
    localDevLoading.value = action.key;
    try {
        const data = await requestJson(panelRoute('websites.local-development', { id: props.website.id }), { body: { action: action.key } });
        pushToast?.(data.message || 'Done.', 'success');
    } catch (error) {
        pushToast?.(error?.message || 'The action failed.', 'error');
    } finally {
        localDevLoading.value = '';
    }
};

const copyToClipboard = (text, toastMessage = '') => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    }
    if (toastMessage) {
        pushToast?.(toastMessage, 'success');
    }
};
</script>

<template>
    <section class="order-2 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:col-start-2 xl:row-start-1 xl:grid-cols-1">
        <div v-if="localDevPermissionCommand" class="rounded-xl border border-blue-200 bg-blue-50/50 p-3 dark:border-blue-800 dark:bg-blue-500/10">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-blue-500 dark:text-blue-400">For Local Development</p>
            <p class="mt-1 text-xs text-blue-700 dark:text-blue-300">Editing this project on this machine with VS Code? Trust the repo, open file permissions, and make Git ignore the mode change.</p>

            <div v-if="canRunLocalDevActions" class="mt-2.5 grid grid-cols-3 gap-1.5">
                <button v-for="action in localDevActions" :key="action.key" type="button"
                    :disabled="Boolean(localDevLoading) || (action.needsGit && !hasGit)"
                    :title="action.needsGit && !hasGit ? 'No Git repository found in this website.' : action.hint"
                    class="flex min-w-0 flex-col items-center gap-1 rounded-lg border border-blue-200 bg-white px-1.5 py-2 text-[11px] font-medium text-blue-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-blue-800 dark:bg-slate-900 dark:text-blue-300 dark:hover:bg-slate-800"
                    @click="runLocalDevAction(action)">
                    <i :class="['bi text-sm', localDevLoading === action.key ? 'bi-arrow-repeat animate-spin' : action.icon]"></i>
                    <span class="w-full truncate text-center">{{ action.label }}</span>
                </button>
            </div>

            <p class="mt-2 flex items-start gap-1.5 text-[11px] font-medium text-amber-600 dark:text-amber-400">
                <i class="bi bi-exclamation-triangle-fill mt-0.5 shrink-0"></i>
                <span>Development risk: "Permission All" opens file permissions to 777. Only use it on your local machine — never on a production server.</span>
            </p>

            <button type="button" class="mt-2 text-[11px] font-medium text-blue-600 hover:underline dark:text-blue-400" @click="showCommands = !showCommands">
                <i :class="['bi mr-0.5', showCommands ? 'bi-chevron-up' : 'bi-chevron-down']"></i>{{ showCommands ? 'Hide commands' : 'Show commands to run yourself' }}
            </button>
            <div v-if="showCommands" class="mt-1.5 space-y-1.5">
                <div v-for="command in [gitSafeDirectoryCommand, localDevPermissionCommand, gitFileModeCommand].filter(Boolean)" :key="command" class="flex items-start gap-1.5">
                    <code class="block min-w-0 flex-1 overflow-x-auto rounded-lg bg-white/70 px-2.5 py-1.5 text-[11px] text-blue-800 dark:bg-slate-900/50 dark:text-blue-300">{{ command }}</code>
                    <button type="button"
                        class="shrink-0 rounded-lg border border-blue-200 bg-white p-1.5 text-blue-500 transition hover:text-blue-700 dark:border-blue-800 dark:bg-slate-900 dark:hover:text-blue-300"
                        @click="copyToClipboard(command, 'Command copied — paste it in your local terminal.')" title="Copy command" aria-label="Copy command">
                        <i class="bi bi-copy text-sm"></i>
                    </button>
                </div>
            </div>
        </div>
        <Deferred data="metrics">
            <template #fallback>
                <div v-for="n in 4" :key="n"
                    class="animate-pulse rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex items-start justify-between">
                        <div class="space-y-2">
                            <div class="h-2.5 w-16 rounded bg-slate-200 dark:bg-slate-700"></div>
                            <div class="h-6 w-12 rounded bg-slate-200 dark:bg-slate-700"></div>
                        </div>
                        <div class="h-10 w-10 rounded-xl bg-slate-200 dark:bg-slate-700"></div>
                    </div>
                </div>
            </template>
            <div v-for="metric in metrics" :key="metric.label"
                class="group rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:shadow-md dark:border-slate-800/80 dark:bg-slate-900/50">
                <div class="flex items-start justify-between">
                    <div>
                        <p
                            class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                            {{ metric.label }}</p>
                        <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{
                            metric.value }}</p>
                        <p v-if="metric.sub" class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">{{
                            metric.sub }}</p>
                    </div>
                    <div
                        :class="['flex h-10 w-10 items-center justify-center rounded-xl transition', metricColorClasses[metric.color]]">
                        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current opacity-80">
                            <path
                                d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" />
                        </svg>
                    </div>
                </div>
            </div>
        </Deferred>
    </section>
</template>
