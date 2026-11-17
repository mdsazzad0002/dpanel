<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import InstallerDatabasePicker from '@/Components/Installer/InstallerDatabasePicker.vue';
import InstallerVersionPicker from '@/Components/Installer/InstallerVersionPicker.vue';
import { engineLabel } from '@/Components/Installer/engines';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    catalog: {
        type: Object,
        default: () => ({ versions: [], stacks: [] }),
    },
    databases: {
        type: Array,
        default: () => [],
    },
    newDatabase: {
        type: Object,
        default: () => null,
    },
    gitRepository: {
        type: Object,
        default: () => null,
    },
    postgresql: {
        type: Object,
        default: () => ({ installed: false, active: false, port: 5432 }),
    },
    databaseEngines: {
        type: Array,
        default: () => ['mariadb'],
    },
    rootInspection: {
        type: Object,
        default: () => null,
    },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const websiteState = ref({ ...props.website });
watch(() => props.website, (next) => {
    websiteState.value = { ...(next || {}) };
}, { deep: true });
const website = computed(() => websiteState.value || {});

const versions = computed(() => props.catalog?.versions || []);
const stacks = computed(() => props.catalog?.stacks || []);

const selectedVersion = ref(versions.value.find((v) => v.php_version)?.value || versions.value[0]?.value || '');
const selectedStack = ref('blank');

const versionMeta = computed(() => versions.value.find((v) => v.value === selectedVersion.value) || null);
const stackAvailable = (stack) => (stack.versions || []).includes(selectedVersion.value);

watch(selectedVersion, () => {
    const current = stacks.value.find((s) => s.value === selectedStack.value);
    if (!current || !stackAvailable(current)) selectedStack.value = 'blank';
});

const selectedDatabaseId = ref(props.databases[0]?.id || 'new');
const selectedDatabase = computed(() => props.databases.find((db) => db.id === selectedDatabaseId.value) || null);

// Engine for a new database; an existing one keeps its own.
const newDatabaseEngine = ref('mariadb');
const effectiveEngine = computed(() => selectedDatabase.value?.engine || newDatabaseEngine.value);

const versionOptions = computed(() => versions.value.map((version) => ({
    value: version.value,
    label: version.label,
    hint: version.php_version ? t('installer.runsOnPhp', { version: version.php_version }) : t('installer.needsPhp', { version: version.min_php }),
    disabled: !version.php_version,
})));

const pushToGit = ref(Boolean(props.gitRepository));
const gitRepositoryLabel = computed(() => props.gitRepository?.repository_full_name || props.gitRepository?.repository_url || '');

const currentPhp = computed(() => String(website.value.php_version || ''));
const targetPhp = computed(() => versionMeta.value?.php_version || null);
const phpWillChange = computed(() => targetPhp.value && targetPhp.value !== currentPhp.value);

const detectedApp = computed(() => String(props.rootInspection?.detected_app ?? '').toLowerCase());
const rootHasFiles = computed(() => !['', 'missing', 'empty'].includes(detectedApp.value));
const rootBadge = computed(() => ({
    laravel: t('installer.badge.laravel'),
    wordpress: t('installer.badge.wordpress'),
    codeigniter: t('installer.badge.codeigniter'),
    unknown: t('installer.badge.unknown'),
    empty: t('installer.badge.empty'),
    missing: t('installer.badge.empty'),
}[detectedApp.value] || t('installer.badge.other')));

const installBusy = ref(false);
const installFeedback = ref('');
const installFeedbackType = ref('success');
const installMessageClass = computed(() => (
    installFeedbackType.value === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
        : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400'
));

const INSTALL_STAGES = ['backing_up', 'downloading', 'creating_database', 'configuring', 'migrating', 'building_assets', 'finalizing', 'pushing_git', 'ready'];
const INSTALL_STAGE_LABELS = computed(() => ({
    queued: t('installer.stage.queued'),
    backing_up: t('installer.stage.backingUp'),
    downloading: t('installer.laravel.stage.downloading'),
    creating_database: t('installer.stage.creatingDatabase'),
    configuring: t('installer.laravel.stage.configuring'),
    migrating: t('installer.laravel.stage.migrating'),
    building_assets: t('installer.laravel.stage.buildingAssets'),
    finalizing: t('installer.laravel.stage.finalizing'),
    pushing_git: t('installer.laravel.stage.pushingGit'),
    ready: t('installer.stage.ready'),
}));

const installStage = ref('');
const installProgress = computed(() => {
    if (!installStage.value || installStage.value === 'failed') return 0;
    if (installStage.value === 'queued') return 3;
    const index = INSTALL_STAGES.indexOf(installStage.value);
    if (index === -1) return 3;
    return Math.round(((index + 1) / INSTALL_STAGES.length) * 100);
});
const installStageLabel = computed(() => INSTALL_STAGE_LABELS.value[installStage.value] || t('installer.working'));

let installPollTimer = null;
const stopInstallPoll = () => {
    window.clearInterval(installPollTimer);
    installPollTimer = null;
};
onUnmounted(stopInstallPoll);

const pollInstallStatus = async (installId) => {
    try {
        const response = await window.axios.get(
            panelRoute('websites.laravel.install.status', { id: website.value.id, installId }),
            { headers: { Accept: 'application/json' } },
        );
        const payload = response?.data || {};
        if (!payload.success) return;

        installStage.value = payload.stage || installStage.value;

        if (payload.stage === 'ready') {
            stopInstallPoll();
            installBusy.value = false;
            if (payload.website) websiteState.value = { ...payload.website };
            installFeedbackType.value = 'success';
            installFeedback.value = payload.message || t('installer.laravel.done');
        } else if (payload.stage === 'failed') {
            stopInstallPoll();
            installBusy.value = false;
            installFeedbackType.value = 'error';
            installFeedback.value = payload.message || t('installer.laravel.failed');
        }
    } catch {
        // Network hiccup — next tick retries.
    }
};

const installLaravel = async () => {
    if (installBusy.value || !targetPhp.value) return;

    const warnings = [];
    if (rootHasFiles.value) warnings.push(t('installer.warnTrash', { path: website.value.root_path }));
    if (selectedDatabase.value) warnings.push(t('installer.laravel.warnWipe', { engine: engineLabel(selectedDatabase.value.engine), name: selectedDatabase.value.database_name }));
    if (phpWillChange.value) warnings.push(t('installer.warnPhp', { from: currentPhp.value || t('installer.none'), to: targetPhp.value }));
    if (warnings.length && !window.confirm(t('installer.confirmContinue', { warnings: warnings.join('\n') }))) return;

    installBusy.value = true;
    installFeedback.value = '';
    installStage.value = 'queued';

    try {
        const response = await window.axios.post(
            panelRoute('websites.laravel.install', { id: website.value.id }),
            {
                stack: selectedStack.value,
                laravel_version: selectedVersion.value,
                database_id: selectedDatabaseId.value,
                database_suffix: props.newDatabase?.suffix || null,
                database_engine: selectedDatabase.value ? null : newDatabaseEngine.value,
                push_to_git: Boolean(props.gitRepository) && pushToGit.value,
            },
            { headers: { Accept: 'application/json' } },
        );
        const installId = response?.data?.install_id;
        stopInstallPoll();
        installPollTimer = window.setInterval(() => pollInstallStatus(installId), 2000);
        pollInstallStatus(installId);
    } catch (error) {
        installBusy.value = false;
        installStage.value = 'failed';
        installFeedbackType.value = 'error';
        installFeedback.value = error?.response?.data?.message || t('installer.laravel.failed');
    }
};
</script>

<template>
    <Head :title="t('installer.laravel.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('installer.laravel.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ t('installer.laravel.subtitle', { domain: website.domain || '-' }) }}
                </p>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="installFeedback" class="whitespace-pre-wrap break-words rounded-md border px-4 py-3 text-sm" :class="installMessageClass">
                {{ installFeedback }}
            </div>

            <div class="flex justify-end">
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    <i class="bi bi-arrow-left mr-2"></i> {{ t('common.back') }}
                </Link>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('installer.domain') }}</p>
                        <p class="mt-1 break-all text-sm font-semibold">{{ website.domain || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('installer.projectRoot') }}</p>
                        <p class="mt-1 break-all text-sm font-semibold">{{ website.root_path || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('installer.currentPhp') }}</p>
                        <p class="mt-1 text-sm font-semibold">{{ currentPhp || '-' }}</p>
                    </div>
                </div>

                <div class="mt-3">
                    <Deferred data="rootInspection">
                        <template #fallback>
                            <span class="inline-flex h-[26px] w-32 animate-pulse rounded-full border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800"></span>
                        </template>
                        <span
                            class="rounded-full border px-3 py-1 text-xs font-medium"
                            :class="detectedApp === 'laravel'
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                                : 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'"
                        >
                            {{ rootBadge }}
                        </span>
                    </Deferred>
                </div>

                <InstallerVersionPicker
                    v-model="selectedVersion"
                    class="mt-5"
                    :label="t('installer.laravel.version')"
                    name="laravel_version"
                    :versions="versionOptions"
                    :disabled="installBusy"
                    accent="red"
                />

                <InstallerDatabasePicker
                    v-model="selectedDatabaseId"
                    v-model:engine="newDatabaseEngine"
                    class="mt-5"
                    :databases="databases"
                    :new-database="newDatabase"
                    :engines="databaseEngines"
                    :postgresql="postgresql"
                    :existing-note="t('installer.laravel.existingNote')"
                    existing-note-class="text-rose-600 dark:text-rose-400"
                    password-file=".env"
                    :disabled="installBusy"
                    accent="red"
                />

                <div class="mt-5">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('installer.laravel.stack') }}</p>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="stack in stacks"
                            :key="stack.value"
                            class="flex cursor-pointer gap-3 rounded-lg border p-3 transition"
                            :class="[
                                selectedStack === stack.value
                                    ? 'border-red-400 bg-red-50/60 dark:border-red-500 dark:bg-red-500/10'
                                    : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600',
                                !stackAvailable(stack) ? 'cursor-not-allowed opacity-50' : '',
                            ]"
                        >
                            <input
                                v-model="selectedStack"
                                type="radio"
                                name="stack"
                                class="mt-1"
                                :value="stack.value"
                                :disabled="installBusy || !stackAvailable(stack)"
                            />
                            <span>
                                <span class="block text-sm font-semibold">{{ stack.label }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ stackAvailable(stack) ? stack.description : t('installer.laravel.needsLaravel', { versions: stack.versions.join(t('installer.laravel.or')) }) }}
                                </span>
                            </span>
                        </label>
                    </div>
                </div>

                <label
                    v-if="gitRepository"
                    class="mt-5 flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700"
                >
                    <input v-model="pushToGit" type="checkbox" class="mt-1 rounded" :disabled="installBusy" />
                    <span>
                        <span class="block text-sm font-semibold">
                            <i class="bi bi-github mr-1"></i> {{ t('installer.laravel.pushTo', { repo: gitRepositoryLabel, branch: gitRepository.branch }) }}
                        </span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ t('installer.laravel.pushHelp') }}
                        </span>
                    </span>
                </label>

                <ul class="mt-5 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                    <li v-if="targetPhp">
                        <i class="bi bi-braces mr-2 text-indigo-500"></i>
                        <template v-if="phpWillChange"><i18n-t keypath="installer.phpSwitch" scope="global"><template #from><strong>{{ currentPhp || t('installer.none') }}</strong></template><template #to><strong>{{ targetPhp }}</strong></template></i18n-t></template>
                        <template v-else><i18n-t keypath="installer.phpCurrent" scope="global"><template #version><strong>{{ targetPhp }}</strong></template></i18n-t></template>
                    </li>
                    <li>
                        <i class="bi bi-database mr-2 text-orange-500"></i>
                        <template v-if="selectedDatabase"><i18n-t keypath="installer.laravel.dbReuse" scope="global"><template #engine>{{ engineLabel(selectedDatabase.engine) }}</template><template #name><strong>{{ selectedDatabase.database_name }}</strong></template></i18n-t></template>
                        <template v-else><i18n-t keypath="installer.laravel.dbNew" scope="global"><template #engine>{{ engineLabel(newDatabaseEngine) }}</template><template #name><strong>{{ newDatabase?.database_name }}</strong></template><template #domain>{{ website.domain }}</template></i18n-t></template>
                        <span v-if="effectiveEngine === 'postgresql'" class="block pl-6 text-xs text-slate-500 dark:text-slate-400">{{ t('installer.laravel.pgNote') }}</span>
                    </li>
                    <li v-if="rootHasFiles">
                        <i class="bi bi-trash mr-2 text-rose-500"></i>
                        {{ t('installer.rootTrash') }}
                    </li>
                    <li>
                        <i class="bi bi-folder2 mr-2 text-emerald-500"></i>
                        <i18n-t keypath="installer.docRoot" scope="global"><template #path><code>public/</code></template></i18n-t>
                    </li>
                </ul>

                <div class="mt-5 flex justify-end">
                    <button
                        type="button"
                        class="rounded-md border px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="installBusy || !targetPhp"
                        :class="installBusy
                            ? 'border-slate-300 text-slate-500 dark:border-slate-700 dark:text-slate-400'
                            : 'border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/20'"
                        @click="installLaravel"
                    >
                        {{ installBusy ? t('installer.installing') : t('installer.laravel.install') }}
                    </button>
                </div>

                <div v-if="installBusy" class="mt-5">
                    <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                        <span>{{ installStageLabel }}</span>
                        <span>{{ installProgress }}%</span>
                    </div>
                    <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div
                            class="h-full rounded-full bg-red-500 transition-all duration-500 ease-out dark:bg-red-400"
                            :style="{ width: installProgress + '%' }"
                        ></div>
                    </div>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('installer.laravel.slow') }}</p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
