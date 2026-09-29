<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import InstallerDatabasePicker from '@/Components/Installer/InstallerDatabasePicker.vue';
import InstallerVersionPicker from '@/Components/Installer/InstallerVersionPicker.vue';
import { engineLabel } from '@/Components/Installer/engines';

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
    hint: version.php_version ? `Runs on PHP ${version.php_version}` : `Needs PHP ${version.min_php}+ (not installed)`,
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
    laravel: 'Laravel detected',
    wordpress: 'WordPress detected',
    codeigniter: 'CodeIgniter detected',
    unknown: 'Existing files',
    empty: 'Empty',
    missing: 'Empty',
}[detectedApp.value] || 'Unknown'));

const installBusy = ref(false);
const installFeedback = ref('');
const installFeedbackType = ref('success');
const installMessageClass = computed(() => (
    installFeedbackType.value === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
        : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400'
));

const INSTALL_STAGES = ['backing_up', 'downloading', 'creating_database', 'configuring', 'migrating', 'building_assets', 'finalizing', 'pushing_git', 'ready'];
const INSTALL_STAGE_LABELS = {
    queued: 'Queued…',
    backing_up: 'Moving existing files to trash…',
    downloading: 'Downloading Laravel with Composer…',
    creating_database: 'Preparing database…',
    configuring: 'Writing .env and app key…',
    migrating: 'Running fresh migrations…',
    building_assets: 'Building frontend assets (npm)…',
    finalizing: 'Switching PHP version and document root…',
    pushing_git: 'Pushing the project to the connected repository…',
    ready: 'Done',
};

const installStage = ref('');
const installProgress = computed(() => {
    if (!installStage.value || installStage.value === 'failed') return 0;
    if (installStage.value === 'queued') return 3;
    const index = INSTALL_STAGES.indexOf(installStage.value);
    if (index === -1) return 3;
    return Math.round(((index + 1) / INSTALL_STAGES.length) * 100);
});
const installStageLabel = computed(() => INSTALL_STAGE_LABELS[installStage.value] || 'Working…');

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
            installFeedback.value = payload.message || 'Laravel installed successfully.';
        } else if (payload.stage === 'failed') {
            stopInstallPoll();
            installBusy.value = false;
            installFeedbackType.value = 'error';
            installFeedback.value = payload.message || 'Laravel installation failed.';
        }
    } catch {
        // Network hiccup — next tick retries.
    }
};

const installLaravel = async () => {
    if (installBusy.value || !targetPhp.value) return;

    const warnings = [];
    if (rootHasFiles.value) warnings.push(`Current files in ${website.value.root_path} will be moved to the File Manager trash.`);
    if (selectedDatabase.value) warnings.push(`${engineLabel(selectedDatabase.value.engine)} database "${selectedDatabase.value.database_name}" will be wiped (migrate:fresh).`);
    if (phpWillChange.value) warnings.push(`Website PHP will switch from ${currentPhp.value || 'none'} to ${targetPhp.value}.`);
    if (warnings.length && !window.confirm(`${warnings.join('\n')}\n\nContinue?`)) return;

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
        installFeedback.value = error?.response?.data?.message || 'Laravel installation failed.';
    }
};
</script>

<template>
    <Head title="Laravel Installer" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Laravel Installer</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Install a fresh Laravel project with a database for {{ website.domain || '-' }}.
                </p>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="installFeedback" class="whitespace-pre-wrap break-words rounded-md border px-4 py-3 text-sm" :class="installMessageClass">
                {{ installFeedback }}
            </div>

            <div class="flex justify-end">
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    <i class="bi bi-arrow-left mr-2"></i> Back to Manage
                </Link>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Domain</p>
                        <p class="mt-1 break-all text-sm font-semibold">{{ website.domain || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Project Root</p>
                        <p class="mt-1 break-all text-sm font-semibold">{{ website.root_path || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Current PHP</p>
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
                    label="Laravel Version"
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
                    existing-note="All tables will be dropped"
                    existing-note-class="text-rose-600 dark:text-rose-400"
                    password-file=".env"
                    :disabled="installBusy"
                    accent="red"
                />

                <div class="mt-5">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Stack</p>
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
                                    {{ stackAvailable(stack) ? stack.description : `Needs Laravel ${stack.versions.join(' or ')}` }}
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
                            <i class="bi bi-github mr-1"></i> Push to {{ gitRepositoryLabel }} ({{ gitRepository.branch }})
                        </span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            Only if the repository is still empty: the new project becomes its first commit. <code>.env</code>, <code>vendor</code> and <code>node_modules</code> are not pushed.
                        </span>
                    </span>
                </label>

                <ul class="mt-5 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                    <li v-if="targetPhp">
                        <i class="bi bi-braces mr-2 text-indigo-500"></i>
                        <template v-if="phpWillChange">PHP will switch from <strong>{{ currentPhp || 'none' }}</strong> to <strong>{{ targetPhp }}</strong>.</template>
                        <template v-else>Runs on the current PHP <strong>{{ targetPhp }}</strong>.</template>
                    </li>
                    <li>
                        <i class="bi bi-database mr-2 text-orange-500"></i>
                        <template v-if="selectedDatabase">{{ engineLabel(selectedDatabase.engine) }} database <strong>{{ selectedDatabase.database_name }}</strong> will be reused and <strong>wiped</strong> by <code>migrate:fresh</code>.</template>
                        <template v-else>A new {{ engineLabel(newDatabaseEngine) }} database <strong>{{ newDatabase?.database_name }}</strong> will be created for {{ website.domain }}.</template>
                        <span v-if="effectiveEngine === 'postgresql'" class="block pl-6 text-xs text-slate-500 dark:text-slate-400">Laravel connects with <code>DB_CONNECTION=pgsql</code>; the site's PHP needs the <code>pdo_pgsql</code> extension.</span>
                    </li>
                    <li v-if="rootHasFiles">
                        <i class="bi bi-trash mr-2 text-rose-500"></i>
                        Current files in the project root will be moved to the File Manager trash.
                    </li>
                    <li>
                        <i class="bi bi-folder2 mr-2 text-emerald-500"></i>
                        The document root will be set to <code>public/</code>.
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
                        {{ installBusy ? 'Installing…' : 'Install Laravel' }}
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
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Composer and the frontend build can take several minutes.</p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
