<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    website: { type: Object, required: true },
    app: { type: Object, required: true },
    catalog: { type: Object, default: () => ({ versions: [], error: null }) },
    databases: { type: Array, default: () => [] },
    newDatabase: { type: Object, default: () => null },
    adminEmail: { type: String, default: '' },
    rootInspection: { type: Object, default: () => null },
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

const isJoomla = computed(() => props.app.key === 'joomla');
const isWhmcs = computed(() => props.app.key === 'whmcs');
const databaseOptional = computed(() => props.app.database === 'optional');
const newDatabaseOnly = computed(() => props.app.database === 'new');

const versions = computed(() => props.catalog?.versions || []);
const selectedVersion = ref(versions.value.find((v) => v.php_version)?.value || versions.value[0]?.value || '');
const versionMeta = computed(() => versions.value.find((v) => v.value === selectedVersion.value) || null);

const selectedDatabaseId = ref('new');
const selectedDatabase = computed(() => props.databases.find((db) => db.id === selectedDatabaseId.value) || null);

const randomPassword = () => {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const bytes = new Uint32Array(20);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, (n) => alphabet[n % alphabet.length]).join('');
};

const joomla = ref({
    site_name: props.website.domain || '',
    admin_name: 'Administrator',
    admin_username: 'admin',
    admin_email: props.adminEmail,
    admin_password: randomPassword(),
});
const showPassword = ref(false);

const whmcs = ref({
    license_key: '',
    admin_username: 'admin',
    admin_password: randomPassword(),
});
const whmcsValid = computed(() => !isWhmcs.value || (
    /^[A-Za-z0-9-]+$/.test(whmcs.value.license_key.trim())
    && /^[A-Za-z0-9_.-]+$/.test(whmcs.value.admin_username)
    && whmcs.value.admin_password.length >= 12
    && Boolean(uploadId.value)
));

// WHMCS zips need a customer login to download, so the admin uploads theirs.
// Uploaded in 5 MB chunks so the panel's PHP upload limit doesn't apply.
const uploadId = ref('');
const uploadName = ref('');
const uploadProgress = ref(0);
const uploadBusy = ref(false);
const uploadError = ref('');

const uploadPackage = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    uploadId.value = '';
    uploadError.value = '';
    uploadName.value = file.name;
    uploadProgress.value = 0;
    uploadBusy.value = true;
    const params = { id: website.value.id, app: props.app.key };
    try {
        const started = await window.axios.post(panelRoute('websites.apps.upload.start', params), { name: file.name, size: file.size });
        const id = started.data.upload_id;
        const chunkSize = 5 * 1024 * 1024;
        const total = Math.ceil(file.size / chunkSize);
        for (let index = 0; index < total; index += 1) {
            const form = new FormData();
            form.append('index', String(index));
            form.append('chunk', file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize)), `${index}.part`);
            await window.axios.post(panelRoute('websites.apps.upload.chunk', { ...params, uploadId: id }), form);
            uploadProgress.value = Math.round(((index + 1) / total) * 100);
        }
        await window.axios.post(panelRoute('websites.apps.upload.complete', { ...params, uploadId: id }), { total });
        uploadId.value = id;
    } catch (error) {
        const errors = error?.response?.data?.errors;
        uploadError.value = errors ? Object.values(errors).flat().join(' ') : (error?.response?.data?.message || 'Upload failed.');
    } finally {
        uploadBusy.value = false;
    }
};
const joomlaValid = computed(() => !isJoomla.value || (
    joomla.value.site_name.trim()
    && joomla.value.admin_name.trim()
    && /^[A-Za-z0-9_.@-]+$/.test(joomla.value.admin_username)
    && /.+@.+\..+/.test(joomla.value.admin_email)
    && joomla.value.admin_password.length >= 12
));

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

const INSTALL_STAGES = ['backing_up', 'downloading', 'creating_database', 'configuring', 'finalizing', 'ready'];
const INSTALL_STAGE_LABELS = computed(() => ({
    queued: 'Queued…',
    backing_up: 'Moving existing files to trash…',
    downloading: isJoomla.value ? 'Downloading and extracting Joomla…' : 'Downloading CodeIgniter with Composer…',
    creating_database: 'Preparing database…',
    configuring: isJoomla.value ? 'Running the Joomla installer…' : 'Writing .env…',
    finalizing: 'Setting permissions, PHP version and document root…',
    ready: 'Done',
}));

const installStage = ref('');
const installProgress = computed(() => {
    if (!installStage.value || installStage.value === 'failed') return 0;
    if (installStage.value === 'queued') return 3;
    const index = INSTALL_STAGES.indexOf(installStage.value);
    if (index === -1) return 3;
    return Math.round(((index + 1) / INSTALL_STAGES.length) * 100);
});
const installStageLabel = computed(() => INSTALL_STAGE_LABELS.value[installStage.value] || 'Working…');

let installPollTimer = null;
const stopInstallPoll = () => {
    window.clearInterval(installPollTimer);
    installPollTimer = null;
};
onUnmounted(stopInstallPoll);

const pollInstallStatus = async (installId) => {
    try {
        const response = await window.axios.get(
            panelRoute('websites.apps.install.status', { id: website.value.id, app: props.app.key, installId }),
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
            installFeedback.value = payload.message || `${props.app.label} installed successfully.`;
        } else if (payload.stage === 'failed') {
            stopInstallPoll();
            installBusy.value = false;
            installFeedbackType.value = 'error';
            installFeedback.value = payload.message || `${props.app.label} installation failed.`;
        }
    } catch {
        // Network hiccup — next tick retries.
    }
};

const install = async () => {
    if (installBusy.value || !targetPhp.value || !joomlaValid.value || !whmcsValid.value) return;

    const warnings = [];
    if (rootHasFiles.value) warnings.push(`Current files in ${website.value.root_path} will be moved to the File Manager trash.`);
    if (phpWillChange.value) warnings.push(`Website PHP will switch from ${currentPhp.value || 'none'} to ${targetPhp.value}.`);
    if (warnings.length && !window.confirm(`${warnings.join('\n')}\n\nContinue?`)) return;

    installBusy.value = true;
    installFeedback.value = '';
    installStage.value = 'queued';

    try {
        const response = await window.axios.post(
            panelRoute('websites.apps.install', { id: website.value.id, app: props.app.key }),
            {
                version: selectedVersion.value,
                database_id: selectedDatabaseId.value,
                database_suffix: props.newDatabase?.suffix || null,
                ...(isJoomla.value ? joomla.value : {}),
                ...(isWhmcs.value ? { ...whmcs.value, license_key: whmcs.value.license_key.trim(), upload_id: uploadId.value } : {}),
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
        const errors = error?.response?.data?.errors;
        installFeedback.value = errors
            ? Object.values(errors).flat().join('\n')
            : (error?.response?.data?.message || `${props.app.label} installation failed.`);
    }
};

const cardClass = (active) => (active
    ? 'border-orange-400 bg-orange-50/60 dark:border-orange-500 dark:bg-orange-500/10'
    : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600');
const inputClass = 'mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <Head :title="`${app.label} Installer`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ app.label }} Installer</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ app.description }} Target: {{ website.domain || '-' }}.</p>
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
                        <span class="rounded-full border border-slate-300 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            {{ rootBadge }}
                        </span>
                    </Deferred>
                </div>

                <div v-if="catalog.error" class="mt-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    {{ catalog.error }}
                </div>

                <div class="mt-5">
                    <label class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Version</label>
                    <select v-model="selectedVersion" :disabled="installBusy || versions.length === 0" :class="inputClass" class="sm:w-72">
                        <option v-for="version in versions" :key="version.value" :value="version.value" :disabled="!version.php_version">
                            {{ version.label }} (PHP {{ version.min_php }}+){{ version.php_version ? '' : ' — PHP not installed' }}
                        </option>
                    </select>
                </div>

                <div v-if="isWhmcs" class="mt-5">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">WHMCS package &amp; license</p>
                    <div class="mt-2 rounded-lg border border-dashed border-slate-300 p-4 text-sm dark:border-slate-700">
                        <p class="text-slate-600 dark:text-slate-300">
                            Download the full WHMCS zip from your WHMCS client area (Services → your license → Downloads) and upload it here.
                        </p>
                        <label class="mt-3 inline-flex cursor-pointer items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" :class="{ 'pointer-events-none opacity-50': uploadBusy || installBusy }">
                            <i class="bi bi-upload"></i> {{ uploadId ? 'Replace zip' : 'Choose WHMCS zip' }}
                            <input type="file" accept=".zip,application/zip" class="hidden" @change="uploadPackage" />
                        </label>
                        <p v-if="uploadName" class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            {{ uploadName }} —
                            <span v-if="uploadBusy">uploading {{ uploadProgress }}%</span>
                            <span v-else-if="uploadId" class="text-emerald-600 dark:text-emerald-400">uploaded and checked</span>
                        </p>
                        <p v-if="uploadError" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ uploadError }}</p>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="text-sm sm:col-span-2">License key<input v-model="whmcs.license_key" type="text" autocomplete="off" placeholder="Owned-xxxxxxxxxxxx" :disabled="installBusy" :class="inputClass" class="font-mono" /></label>
                        <label class="text-sm">Admin username<input v-model="whmcs.admin_username" type="text" autocomplete="off" :disabled="installBusy" :class="inputClass" /></label>
                        <label class="text-sm">
                            Admin password <span class="text-xs text-slate-500">(12+ characters — save it now)</span>
                            <div class="flex gap-2">
                                <input v-model="whmcs.admin_password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" :disabled="installBusy" :class="inputClass" class="font-mono" />
                                <button type="button" class="mt-1 rounded-md border border-slate-300 px-3 text-xs dark:border-slate-700" @click="showPassword = !showPassword">{{ showPassword ? 'Hide' : 'Show' }}</button>
                                <button type="button" class="mt-1 rounded-md border border-slate-300 px-3 text-xs dark:border-slate-700" :disabled="installBusy" @click="whmcs.admin_password = randomPassword(); showPassword = true">Generate</button>
                            </div>
                        </label>
                    </div>
                </div>

                <div v-if="isJoomla" class="mt-5">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Site &amp; administrator</p>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Site name<input v-model="joomla.site_name" type="text" :disabled="installBusy" :class="inputClass" /></label>
                        <label class="text-sm">Admin full name<input v-model="joomla.admin_name" type="text" :disabled="installBusy" :class="inputClass" /></label>
                        <label class="text-sm">Admin username<input v-model="joomla.admin_username" type="text" autocomplete="off" :disabled="installBusy" :class="inputClass" /></label>
                        <label class="text-sm">Admin email<input v-model="joomla.admin_email" type="email" :disabled="installBusy" :class="inputClass" /></label>
                        <label class="text-sm sm:col-span-2">
                            Admin password <span class="text-xs text-slate-500">(at least 12 characters — save it now, it is not stored by the panel)</span>
                            <div class="flex gap-2">
                                <input v-model="joomla.admin_password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" :disabled="installBusy" :class="inputClass" class="font-mono" />
                                <button type="button" class="mt-1 rounded-md border border-slate-300 px-3 text-xs dark:border-slate-700" @click="showPassword = !showPassword">{{ showPassword ? 'Hide' : 'Show' }}</button>
                                <button type="button" class="mt-1 rounded-md border border-slate-300 px-3 text-xs dark:border-slate-700" :disabled="installBusy" @click="joomla.admin_password = randomPassword(); showPassword = true">Generate</button>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="mt-5">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Database</p>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label v-for="db in (newDatabaseOnly ? [] : databases)" :key="db.id" class="flex cursor-pointer gap-3 rounded-lg border p-3 transition" :class="cardClass(selectedDatabaseId === db.id)">
                            <input v-model="selectedDatabaseId" type="radio" name="database" class="mt-1" :value="db.id" :disabled="installBusy" />
                            <span class="min-w-0">
                                <span class="block break-all text-sm font-semibold">{{ db.database_name }}</span>
                                <span class="block break-all text-xs text-slate-500 dark:text-slate-400">User: {{ db.database_user }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ isJoomla ? 'Existing tables are kept; Joomla uses a new table prefix' : 'Credentials are written to .env' }}</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer gap-3 rounded-lg border p-3 transition" :class="cardClass(selectedDatabaseId === 'new')">
                            <input v-model="selectedDatabaseId" type="radio" name="database" class="mt-1" value="new" :disabled="installBusy" />
                            <span>
                                <span class="block text-sm font-semibold">Create new database</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">A fresh database and user for this website</span>
                            </span>
                        </label>
                        <label v-if="databaseOptional" class="flex cursor-pointer gap-3 rounded-lg border p-3 transition" :class="cardClass(selectedDatabaseId === 'none')">
                            <input v-model="selectedDatabaseId" type="radio" name="database" class="mt-1" value="none" :disabled="installBusy" />
                            <span>
                                <span class="block text-sm font-semibold">No database</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">Configure one later in .env</span>
                            </span>
                        </label>
                    </div>

                    <div v-if="selectedDatabaseId === 'new' && newDatabase" class="mt-3 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3 text-sm dark:border-slate-700 dark:bg-slate-800/50">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Will be created</p>
                        <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                            <div class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">Database:</dt><dd class="break-all font-mono">{{ newDatabase.database_name }}</dd></div>
                            <div class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">User:</dt><dd class="break-all font-mono">{{ newDatabase.database_user }}</dd></div>
                            <div class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">Password:</dt><dd>auto-generated, saved in <code>{{ isJoomla ? 'configuration.php' : '.env' }}</code></dd></div>
                            <div class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">Host:</dt><dd class="font-mono">{{ newDatabase.database_host }}</dd></div>
                        </dl>
                    </div>
                </div>

                <ul class="mt-5 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                    <li v-if="targetPhp">
                        <i class="bi bi-braces mr-2 text-indigo-500"></i>
                        <template v-if="phpWillChange">PHP will switch from <strong>{{ currentPhp || 'none' }}</strong> to <strong>{{ targetPhp }}</strong>.</template>
                        <template v-else>Runs on the current PHP <strong>{{ targetPhp }}</strong>.</template>
                    </li>
                    <li v-if="rootHasFiles">
                        <i class="bi bi-trash mr-2 text-rose-500"></i>
                        Current files in the project root will be moved to the File Manager trash.
                    </li>
                    <li>
                        <i class="bi bi-folder2 mr-2 text-emerald-500"></i>
                        <template v-if="app.document_root">The document root will be set to <code>{{ app.document_root }}/</code>.</template>
                        <template v-else>The site is served from the project root.</template>
                    </li>
                    <li v-if="isWhmcs">
                        <i class="bi bi-shield-lock mr-2 text-amber-500"></i>
                        Needs the ionCube Loader for PHP {{ targetPhp || '8.1–8.3' }}. The <code>install/</code> folder is removed, a 5-minute cron for <code>crons/cron.php</code> is added, and the admin area is <code>/admin</code>.
                    </li>
                    <li v-if="isJoomla">
                        <i class="bi bi-shield-lock mr-2 text-amber-500"></i>
                        The <code>installation/</code> folder is removed after setup. Admin panel: <code>/administrator</code>.
                    </li>
                </ul>

                <div class="mt-5 flex justify-end">
                    <button
                        type="button"
                        class="rounded-md border px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="installBusy || uploadBusy || !targetPhp || !joomlaValid || !whmcsValid"
                        :class="installBusy
                            ? 'border-slate-300 text-slate-500 dark:border-slate-700 dark:text-slate-400'
                            : 'border-orange-300 text-orange-700 hover:bg-orange-50 dark:border-orange-700 dark:text-orange-300 dark:hover:bg-orange-900/20'"
                        @click="install"
                    >
                        {{ installBusy ? 'Installing…' : `Install ${app.label}` }}
                    </button>
                </div>

                <div v-if="installBusy" class="mt-5">
                    <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                        <span>{{ installStageLabel }}</span>
                        <span>{{ installProgress }}%</span>
                    </div>
                    <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full bg-orange-500 transition-all duration-500 ease-out dark:bg-orange-400" :style="{ width: installProgress + '%' }"></div>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
