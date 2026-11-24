<script setup>
import { useI18n } from 'vue-i18n';
import { computed, inject, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { useServerBaseDir } from '@/composables/useServerBaseDir';

const { t } = useI18n();

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    rootInspection: {
        type: Object,
        default: () => ({}),
    },
});

const pushToast = inject('pushToast');
const { panelRoute, requestJson } = usePanelApi();
const page = usePage();

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

// The panel can only run these for a website account under the base folder;
// the /var/www system site keeps the copy-and-run commands only.
const { isInsideBase } = useServerBaseDir();
const canRunLocalDevActions = computed(() => isInsideBase(props.website?.root_path));
const hasGit = computed(() => Boolean(props.rootInspection?.has_git));
const showCommands = ref(false);
const localDevLoading = ref('');

const localDevActions = computed(() => [
    { key: 'trust', label: t('websites.metrics.trust'), icon: 'bi-shield-check', needsGit: true, hint: t('websites.metrics.trustHint') },
    { key: 'open_permissions', label: t('websites.metrics.permAll'), icon: 'bi-unlock', needsGit: false, hint: t('websites.metrics.permAllHint') },
    { key: 'ignore_file_mode', label: t('websites.metrics.ignoreMode'), icon: 'bi-git', needsGit: true, hint: t('websites.metrics.ignoreModeHint') },
// Windows has no Unix modes to open up.
].filter((action) => action.key !== 'open_permissions' || page.props.platform?.capabilities?.file_permissions !== false));

const runLocalDevAction = async (action) => {
    if (localDevLoading.value) return;
    if (action.key === 'open_permissions'
        && !window.confirm(t('websites.metrics.permConfirm', { path: permissionRoot.value }))) return;
    localDevLoading.value = action.key;
    try {
        const data = await requestJson(panelRoute('websites.local-development', { id: props.website.id }), { body: { action: action.key } });
        pushToast?.(data.message || t('websites.metrics.done'), 'success');
    } catch (error) {
        pushToast?.(error?.message || t('websites.metrics.failed'), 'error');
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
    <div v-if="localDevPermissionCommand" class="rounded-xl border border-blue-200 bg-blue-50/50 p-3 dark:border-blue-800 dark:bg-blue-500/10">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-blue-500 dark:text-blue-400">{{ t('websites.metrics.localDev') }}</p>
        <p class="mt-1 text-xs text-blue-700 dark:text-blue-300">{{ t('websites.metrics.localDevHelp') }}</p>

        <div v-if="canRunLocalDevActions" class="mt-2.5 grid grid-cols-3 gap-1.5">
            <button v-for="action in localDevActions" :key="action.key" type="button"
                :disabled="Boolean(localDevLoading) || (action.needsGit && !hasGit)"
                :title="action.needsGit && !hasGit ? t('websites.metrics.noGit') : action.hint"
                class="flex min-w-0 flex-col items-center gap-1 rounded-lg border border-blue-200 bg-white px-1.5 py-2 text-[11px] font-medium text-blue-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-blue-800 dark:bg-slate-900 dark:text-blue-300 dark:hover:bg-slate-800"
                @click="runLocalDevAction(action)">
                <i :class="['bi text-sm', localDevLoading === action.key ? 'bi-arrow-repeat animate-spin' : action.icon]"></i>
                <span class="w-full truncate text-center">{{ action.label }}</span>
            </button>
        </div>

        <p class="mt-2 flex items-start gap-1.5 text-[11px] font-medium text-amber-600 dark:text-amber-400">
            <i class="bi bi-exclamation-triangle-fill mt-0.5 shrink-0"></i>
            <span>{{ t('websites.metrics.risk') }}</span>
        </p>

        <button type="button" class="mt-2 text-[11px] font-medium text-blue-600 hover:underline dark:text-blue-400" @click="showCommands = !showCommands">
            <i :class="['bi mr-0.5', showCommands ? 'bi-chevron-up' : 'bi-chevron-down']"></i>{{ showCommands ? t('websites.metrics.hideCommands') : t('websites.metrics.showCommands') }}
        </button>
        <div v-if="showCommands" class="mt-1.5 space-y-1.5">
            <div v-for="command in [gitSafeDirectoryCommand, localDevPermissionCommand, gitFileModeCommand].filter(Boolean)" :key="command" class="flex items-start gap-1.5">
                <code class="block min-w-0 flex-1 overflow-x-auto rounded-lg bg-white/70 px-2.5 py-1.5 text-[11px] text-blue-800 dark:bg-slate-900/50 dark:text-blue-300">{{ command }}</code>
                <button type="button"
                    class="shrink-0 rounded-lg border border-blue-200 bg-white p-1.5 text-blue-500 transition hover:text-blue-700 dark:border-blue-800 dark:bg-slate-900 dark:hover:text-blue-300"
                    @click="copyToClipboard(command, t('websites.metrics.commandCopied'))" :title="t('websites.metrics.copyCommand')" :aria-label="t('websites.metrics.copyCommand')">
                    <i class="bi bi-copy text-sm"></i>
                </button>
            </div>
        </div>
    </div>
</template>
