<script setup>
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    savedConnections: { type: Array, default: () => [] },
    phpVersions: { type: Array, default: () => [] },
});
const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const savedConnections = ref([...props.savedConnections]);
const selectedConnectionId = ref('');
const ssh = reactive({ host: '', port: 22, username: 'root', auth_type: 'password', password: '', private_key: '', key_passphrase: '', remember_access: false, connection_name: '' });
const inspecting = ref(false);
const inventory = ref(null);
const message = ref('');
const error = ref('');
const downloading = ref('');

async function loadSavedConnection() {
    if (!selectedConnectionId.value) return;
    error.value = '';
    try {
        const response = await axios.get(route('migrations.cyberpanel-ssh.connections.show', { token: panelToken.value, connection: selectedConnectionId.value }));
        Object.assign(ssh, response.data.connection);
        message.value = `Loaded ${ssh.connection_name}.`;
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Could not load the saved SSH connection.';
    }
}

async function deleteSavedConnection() {
    if (!selectedConnectionId.value || !window.confirm('Delete this saved SSH connection?')) return;
    try {
        await axios.delete(route('migrations.cyberpanel-ssh.connections.destroy', { token: panelToken.value, connection: selectedConnectionId.value }));
        savedConnections.value = savedConnections.value.filter(item => item.id !== selectedConnectionId.value);
        selectedConnectionId.value = '';
        Object.assign(ssh, { host: '', port: 22, username: 'root', auth_type: 'password', password: '', private_key: '', key_passphrase: '', remember_access: false, connection_name: '' });
        message.value = 'Saved SSH connection deleted.';
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Could not delete the saved SSH connection.';
    }
}
const availablePhpVersions = computed(() => {
    const versions = props.phpVersions.map(version => String(version || '').trim()).filter(version => /^\d+\.\d+$/.test(version));
    return versions.length ? versions : ['8.3', '8.2', '8.1', '8.0', '7.4'];
});
const selection = reactive({ domain: '', target_domain: '', source_path: '', source_database: '', php_version: availablePhpVersions.value[0], is_subdomain: false, parent_domain: '', subdomain_prefix: '' });
const parentDomains = ref([]);
let parentSearchTimer = null;
const normalizedSubdomainPrefix = computed(() => selection.subdomain_prefix.trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 63));
const targetDomain = computed(() => {
    if (!selection.is_subdomain) return selection.target_domain.trim().toLowerCase();
    const parent = selection.parent_domain.trim().toLowerCase();
    return normalizedSubdomainPrefix.value && parent ? `${normalizedSubdomainPrefix.value}.${parent}` : '';
});
watch(() => selection.parent_domain, (value) => {
    clearTimeout(parentSearchTimer);
    if (!selection.is_subdomain) return;
    parentSearchTimer = setTimeout(async () => {
        try {
            const response = await axios.get(route('websites.parent-domains.search', { token: panelToken.value }), { params: { q: String(value || '').trim(), limit: 10 } });
            parentDomains.value = response.data.data || [];
        } catch {
            parentDomains.value = [];
        }
    }, 250);
});
const run = ref(null);
const createdWebsiteId = ref('');
const migrating = ref(false);
const workflowSteps = reactive([
    { key: 'prepare', label: 'Prepare temp storage', status: 'waiting' },
    { key: 'download', label: 'Download selected source', status: 'waiting' },
    { key: 'website', label: 'Create website account', status: 'waiting' },
    { key: 'restore', label: 'Move files and import database', status: 'waiting' },
    { key: 'verify', label: 'Verify website live', status: 'waiting' },
]);

function selectWebsite() {
    const website = inventory.value?.websites?.find(item => item.domain === selection.domain);
    if (!website) return;
    selection.source_path = website.path || '';
    selection.source_database = website.databases?.[0]?.name || '';
    selection.target_domain = website.domain;
    if (/^\d+\.\d+$/.test(website.php_version || '')) selection.php_version = website.php_version;
}

async function inspect() {
    inspecting.value = true;
    error.value = '';
    message.value = '';
    inventory.value = null;
    try {
        const response = await axios.post(route('migrations.cyberpanel-ssh.inspect', { token: panelToken.value }), ssh);
        inventory.value = response.data.inventory;
        if (response.data.saved_connection) {
            savedConnections.value = [response.data.saved_connection, ...savedConnections.value.filter(item => item.id !== response.data.saved_connection.id)];
            selectedConnectionId.value = response.data.saved_connection.id;
        }
        const firstWebsite = inventory.value.websites?.[0];
        selection.domain = firstWebsite?.domain || '';
        selection.target_domain = firstWebsite?.domain || '';
        selection.source_path = firstWebsite?.path || inventory.value.directories?.[0] || '';
        selection.source_database = firstWebsite?.databases?.[0]?.name || '';
        message.value = `Connected to ${inventory.value.hostname}. ${inventory.value.websites.length} website(s) found.`;
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Could not inspect the CyberPanel server.';
    } finally {
        inspecting.value = false;
    }
}

function markStep(key, status) {
    const step = workflowSteps.find(item => item.key === key);
    if (step) step.status = status;
}

async function migrate() {
    migrating.value = true;
    error.value = '';
    message.value = '';
    workflowSteps.filter(step => step.status === 'failed').forEach(step => { step.status = 'waiting'; });
    try {
        if (workflowSteps.find(step => step.key === 'prepare').status !== 'completed') {
            markStep('prepare', 'running');
            const prepared = await axios.post(route('migrations.cyberpanel-ssh.prepare', { token: panelToken.value }), {
                domain: targetDomain.value,
                source_path: selection.source_path,
                database: selection.source_database || null,
                php_version: selection.php_version,
            });
            run.value = prepared.data.run;
            markStep('prepare', 'completed');
        }

        if (workflowSteps.find(step => step.key === 'download').status !== 'completed') {
            markStep('download', 'running');
            await axios.post(route('migrations.cyberpanel-ssh.stage', { token: panelToken.value, migrationImport: run.value.id }), ssh, { timeout: 0 });
            markStep('download', 'completed');
        }

        if (workflowSteps.find(step => step.key === 'website').status !== 'completed') {
            markStep('website', 'running');
            let parent = null;
            if (selection.is_subdomain) {
                const parentDomain = selection.parent_domain.trim().toLowerCase();
                const parents = await axios.get(route('websites.parent-domains.search', { token: panelToken.value }), { params: { q: parentDomain, limit: 10 } });
                parent = (parents.data.data || []).find(item => String(item.domain).toLowerCase() === parentDomain) || null;
                if (!parent) {
                    const parentCreated = await axios.post(route('websites.store', { token: panelToken.value }), {
                        domain: parentDomain,
                        root_path: null,
                        start_directory: 'public',
                        php_version: selection.php_version,
                        domain_type: 'main',
                        enable_ssl: false,
                        manage_dns: false,
                    });
                    parent = parentCreated.data.website;
                }
            }
            const created = await axios.post(route('websites.store', { token: panelToken.value }), {
                domain: targetDomain.value,
                root_path: null,
                start_directory: 'public',
                php_version: selection.php_version,
                domain_type: selection.is_subdomain ? 'sub' : 'main',
                parent_id: parent?.id || null,
                parent_domain: parent?.domain || null,
                subdomain_prefix: selection.is_subdomain ? normalizedSubdomainPrefix.value : null,
                enable_ssl: false,
                manage_dns: false,
            });
            createdWebsiteId.value = created.data.website.id;
            markStep('website', 'completed');
        }

        markStep('restore', 'running');
        markStep('verify', 'running');
        const restored = await axios.post(route('migrations.cyberpanel-ssh.restore', { token: panelToken.value, migrationImport: run.value.id }), { website_id: createdWebsiteId.value }, { timeout: 0 });
        run.value = restored.data.run;
        markStep('restore', 'completed');
        markStep('verify', 'completed');
        message.value = restored.data.message;
    } catch (exception) {
        const running = workflowSteps.find(step => step.status === 'running');
        if (running) running.status = 'failed';
        workflowSteps.filter(step => step.status === 'running').forEach(step => { step.status = 'waiting'; });
        error.value = exception.response?.data?.message || Object.values(exception.response?.data?.errors || {})?.[0]?.[0] || 'Migration step failed. Retry after fixing the reported problem.';
    } finally {
        migrating.value = false;
    }
}

async function download(type) {
    downloading.value = type;
    error.value = '';
    message.value = type === 'files' ? 'Creating remote files archive…' : 'Creating remote database dump…';
    try {
        const response = await axios.post(route('migrations.cyberpanel-ssh.download', { token: panelToken.value }), {
            ...ssh,
            type,
            source_path: selection.source_path || null,
            database: selection.source_database || null,
        }, { responseType: 'blob', timeout: 0 });
        const disposition = response.headers['content-disposition'] || '';
        const match = disposition.match(/filename="?([^";]+)"?/i);
        const fallback = type === 'files' ? 'website-files.tar.gz' : `${selection.source_database}.sql`;
        const url = URL.createObjectURL(response.data);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = match?.[1] || fallback;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
        message.value = `${anchor.download} download started.`;
    } catch (exception) {
        let detail = 'Selected source could not be downloaded.';
        if (exception.response?.data instanceof Blob) {
            try { detail = JSON.parse(await exception.response.data.text()).message || detail; } catch { /* keep fallback */ }
        }
        error.value = detail;
        message.value = '';
    } finally {
        downloading.value = '';
    }
}
</script>

<template>
    <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 dark:text-indigo-400" @click="router.visit(route('migrations.index', { token: panelToken }))"><i class="bi bi-arrow-left"></i> All migration services</button>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Connect to CyberPanel</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Credentials are used for the current request. They are stored only when you explicitly enable Save SSH access.</p>
        <div v-if="savedConnections.length" class="mt-5 flex flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800 sm:flex-row">
            <select v-model="selectedConnectionId" class="min-w-0 flex-1 rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900"><option value="">Select saved panel</option><option v-for="connection in savedConnections" :key="connection.id" :value="connection.id">{{ connection.name }} · {{ connection.username }}@{{ connection.host }}:{{ connection.port }}</option></select>
            <button type="button" :disabled="!selectedConnectionId" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40" @click="loadSavedConnection">Use connection</button>
            <button type="button" :disabled="!selectedConnectionId" class="rounded-md border border-red-300 px-4 py-2 text-sm font-semibold text-red-600 disabled:opacity-40 dark:border-red-800" @click="deleteSavedConnection">Delete</button>
        </div>
        <form class="mt-6 grid gap-4 md:grid-cols-2" @submit.prevent="inspect">
            <label class="text-sm font-medium dark:text-slate-300">Host<input v-model.trim="ssh.host" required placeholder="server.example.com" class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
            <label class="text-sm font-medium dark:text-slate-300">SSH port<input v-model.number="ssh.port" required type="number" min="1" max="65535" class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
            <label class="text-sm font-medium dark:text-slate-300">Username<input v-model.trim="ssh.username" required class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
            <label class="text-sm font-medium dark:text-slate-300">Authentication<select v-model="ssh.auth_type" class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"><option value="password">Password</option><option value="key">Private key</option></select></label>
            <label v-if="ssh.auth_type === 'password'" class="text-sm font-medium dark:text-slate-300 md:col-span-2">Password<input v-model="ssh.password" required type="password" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
            <template v-else><label class="text-sm font-medium dark:text-slate-300 md:col-span-2">Private key<textarea v-model="ssh.private_key" required rows="7" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"></textarea></label><label class="text-sm font-medium dark:text-slate-300 md:col-span-2">Key passphrase <span class="font-normal text-slate-400">(optional)</span><input v-model="ssh.key_passphrase" type="password" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label></template>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-300 md:col-span-2">
                <label class="flex items-start gap-2"><input v-model="ssh.remember_access" type="checkbox" class="mt-0.5 rounded" /><span><b>Save SSH access</b><small class="mt-1 block">Not recommended unless necessary. Password/private key will be encrypted in DPanel's database. Never enable this on an untrusted panel installation.</small></span></label>
                <label v-if="ssh.remember_access" class="mt-3 block text-xs font-semibold">Connection name<input v-model.trim="ssh.connection_name" required placeholder="Production CyberPanel" class="mt-1 block w-full rounded-md border-amber-300 bg-white text-sm text-slate-800 dark:border-amber-700 dark:bg-slate-900 dark:text-slate-200" /></label>
            </div>
            <div class="md:col-span-2"><button :disabled="inspecting" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><i class="bi bi-search mr-2"></i>{{ inspecting ? 'Connecting…' : 'Connect & discover' }}</button></div>
        </form>
    </section>
    <div v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ message }}</div>
    <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ error }}</div>
    <section v-if="inventory" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <div><h3 class="text-lg font-semibold dark:text-slate-100">Select one migration source</h3><p class="text-sm text-slate-500">{{ inventory.panel }} · {{ inventory.hostname }} · {{ inventory.directories?.length || 0 }} directories · {{ inventory.databases?.length || 0 }} databases</p></div>
        <div class="mt-5 grid gap-4 md:grid-cols-2">
            <label class="text-sm font-medium dark:text-slate-300">Domain <span class="font-normal text-slate-400">(optional helper)</span><select v-model="selection.domain" class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" @change="selectWebsite"><option value="">Custom selection</option><option v-for="website in inventory.websites" :key="website.domain" :value="website.domain">{{ website.domain }}</option></select></label>
            <label class="text-sm font-medium dark:text-slate-300">Source database<select v-model="selection.source_database" class="mt-1 block w-full rounded-lg border-slate-300 font-mono dark:border-slate-700 dark:bg-slate-800"><option value="">No database</option><option v-for="database in inventory.databases" :key="database" :value="database">{{ database }}</option></select></label>
            <label class="text-sm font-medium dark:text-slate-300 md:col-span-2">Source file location<input v-model.trim="selection.source_path" list="cyberpanel-source-directories" placeholder="/home/example.com/public_html" class="mt-1 block w-full rounded-lg border-slate-300 font-mono dark:border-slate-700 dark:bg-slate-800" /><datalist id="cyberpanel-source-directories"><option v-for="directory in inventory.directories" :key="directory" :value="directory" /></datalist><span class="mt-1 block text-xs font-normal text-slate-400">Type any valid location or select a discovered directory.</span></label>
            <label class="text-sm font-medium dark:text-slate-300">PHP version<select v-model="selection.php_version" required class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"><option v-for="version in availablePhpVersions" :key="version" :value="version">PHP {{ version }}</option></select></label>
            <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium dark:border-slate-700 dark:text-slate-300"><input v-model="selection.is_subdomain" type="checkbox" class="rounded" /> Create as subdomain</label>
            <template v-if="selection.is_subdomain">
                <label class="text-sm font-medium dark:text-slate-300">Parent domain<input v-model.trim="selection.parent_domain" required list="migration-parent-domains" placeholder="example.com" class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /><datalist id="migration-parent-domains"><option v-for="parent in parentDomains" :key="parent.id" :value="parent.domain" /></datalist><span class="mt-1 block text-xs font-normal text-slate-400">Select an existing parent or type a new one. A missing parent will be created automatically.</span></label>
                <label class="text-sm font-medium dark:text-slate-300">Subdomain name<input v-model.trim="selection.subdomain_prefix" required placeholder="app" class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /><span v-if="targetDomain" class="mt-1 block text-xs font-normal text-indigo-500">Will create: {{ targetDomain }}</span></label>
            </template>
            <label v-else class="text-sm font-medium dark:text-slate-300">New DPanel domain<input v-model.trim="selection.target_domain" required placeholder="example.com" class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
        </div>
        <div class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800 dark:border-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-300"><i class="bi bi-info-circle mr-1"></i>Only this file location and this database will be handled. Other websites and databases will not be included.</div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="button" :disabled="!selection.source_path || !!downloading" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-40" @click="download('files')"><i class="bi bi-file-earmark-zip mr-2"></i>{{ downloading === 'files' ? 'Preparing files…' : 'Download selected files' }}</button>
            <button type="button" :disabled="!selection.source_database || !!downloading" class="rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-40" @click="download('database')"><i class="bi bi-database-down mr-2"></i>{{ downloading === 'database' ? 'Preparing database…' : 'Download selected database' }}</button>
        </div>
        <div class="mt-5 space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
            <div v-for="step in workflowSteps" :key="step.key" class="flex items-center justify-between gap-3 text-sm"><span class="text-slate-700 dark:text-slate-300">{{ step.label }}</span><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="step.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : step.status === 'running' ? 'bg-indigo-100 text-indigo-700' : step.status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-500'">{{ step.status }}</span></div>
        </div>
        <button type="button" :disabled="migrating || !targetDomain || !selection.source_path" class="mt-4 rounded-lg bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-40" @click="migrate"><i class="bi bi-play-circle mr-2"></i>{{ migrating ? 'Running current step…' : 'Create & migrate step by step' }}</button>
    </section>
</template>
