<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ website: Object, deployment: Object, repositoryConnected: Boolean, logs: { type: Array, default: () => [] } });
const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);
const csrf = computed(() => document.querySelector('meta[name="csrf-token"]')?.content || '');
const form = ref({ repository_url: props.deployment?.repository_url || '', branch: props.deployment?.branch || 'main', auth_username: props.deployment?.auth_username || '', auth_token: '', auto_action: props.deployment?.auto_action || 'off', interval_minutes: props.deployment?.interval_minutes || 15, enabled: props.deployment?.enabled ?? true });
const deployment = ref(props.deployment);
const logs = ref(props.logs);
const accessType = ref(props.deployment?.has_token ? 'private' : 'public');
const showToken = ref(false);
const busy = ref('');
const progressText = ref('');
const activePanel = ref(props.deployment ? 'deploy' : 'connect');
const notice = ref('');
const error = ref('');
const yamlCopied = ref(false);

const repoValid = computed(() => /^https:\/\/(github\.com|gitlab\.com|bitbucket\.org)\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+(?:\.git)?\/?$/i.test(form.value.repository_url));
const branchValid = computed(() => /^[A-Za-z0-9._/-]+$/.test(form.value.branch) && !form.value.branch.includes('..'));
const credentialsReady = computed(() => accessType.value === 'public' || Boolean(form.value.auth_token || deployment.value?.has_token));
const canSave = computed(() => repoValid.value && branchValid.value && credentialsReady.value && !busy.value);
const cloned = computed(() => props.repositoryConnected || logs.value.some((log) => log.action === 'clone' && log.status === 'success'));
const currentStep = computed(() => !deployment.value ? 1 : !cloned.value ? 2 : 3);
const actionHelp = computed(() => {
    if (!deployment.value) return 'Repository details save করলে deployment buttons চালু হবে।';
    if (!cloned.value) return 'এখন “Deploy website” চাপুন। Website folder খালি থাকতে হবে।';
    return 'Repository connected. নতুন version আনতে Pull, server changes পাঠাতে Push ব্যবহার করুন।';
});
const githubActionsYaml = computed(() => [
    'name: Deploy website', '', 'on:', '  push:',
    `    branches: [${JSON.stringify(form.value.branch || 'main')}]`,
    '  workflow_dispatch:', '', 'concurrency:',
    `  group: deploy-${String(props.website.id)}`,
    '  cancel-in-progress: false', '', 'jobs:', '  deploy:',
    '    runs-on: ubuntu-latest', '    permissions:', '      contents: read',
    '    env:', `      DEPLOY_PATH: ${JSON.stringify(props.website.root_path || '')}`,
    "      SSH_HOST: ${{ secrets.SSH_HOST }}", "      SSH_USER: ${{ secrets.SSH_USER }}",
    "      SSH_PORT: ${{ secrets.SSH_PORT || '22' }}", '', '    steps:',
    '      - name: Checkout project', '        uses: actions/checkout@v5', '',
    '      - name: Configure SSH', '        env:',
    "          SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}",
    "          SSH_KNOWN_HOSTS: ${{ secrets.SSH_KNOWN_HOSTS }}", '        run: |',
    '          install -m 700 -d ~/.ssh',
    '          printf \'%s\\n\' "$SSH_PRIVATE_KEY" > ~/.ssh/deploy_key',
    '          printf \'%s\\n\' "$SSH_KNOWN_HOSTS" > ~/.ssh/known_hosts',
    '          chmod 600 ~/.ssh/deploy_key ~/.ssh/known_hosts', '',
    '      - name: Deploy files', '        run: |', '          rsync -az \\',
    "            --exclude='.git/' \\", "            --exclude='.github/' \\",
    "            --exclude='.env' \\", "            --exclude='node_modules/' \\",
    "            --exclude='vendor/' \\", "            --exclude='storage/' \\",
    '            -e "ssh -i ~/.ssh/deploy_key -p $SSH_PORT" \\',
    '            ./ "$SSH_USER@$SSH_HOST:$DEPLOY_PATH/"',
].join('\n'));

const copyYaml = async () => {
    try {
        await navigator.clipboard.writeText(githubActionsYaml.value);
        yamlCopied.value = true;
        window.setTimeout(() => { yamlCopied.value = false; }, 2000);
    } catch (e) {
        error.value = 'YAML copy করা যায়নি। Code select করে manually copy করুন।';
    }
};

const request = async (url, method, body) => {
    const response = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf.value, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(' ') || 'Request failed.');
    return data;
};
const save = async () => {
    if (!canSave.value) return false;
    busy.value = 'save'; progressText.value = 'Saving repository connection…'; notice.value = ''; error.value = '';
    const payload = { ...form.value };
    payload.clear_token = accessType.value === 'public';
    if (accessType.value === 'public') { payload.auth_username = ''; payload.auth_token = ''; }
    try {
        const data = await request(panelRoute('websites.git.store', { id: props.website.id }), 'PUT', payload);
        deployment.value = data.deployment; form.value.auth_token = '';
        notice.value = 'Connection saved. You can now deploy the repository.';
        activePanel.value = 'deploy';
        return true;
    } catch (e) { error.value = e.message; return false; } finally { busy.value = ''; progressText.value = ''; }
};
const run = async (action, skipConfirmation = false) => {
    if (!deployment.value) { error.value = 'Step 1 complete করুন: আগে connection save করুন।'; return; }
    if (action === 'clone' && !skipConfirmation && !confirm(`Deploy ${form.value.branch} branch to ${props.website.domain}?\n\nThe website root must be empty. Existing files will not be deleted.`)) return;
    busy.value = action; progressText.value = action === 'clone' ? 'Connecting repository and downloading code…' : `${operationLabel(action)}…`; notice.value = ''; error.value = '';
    try {
        const data = await request(panelRoute('websites.git.run', { id: props.website.id }), 'POST', { action, message: `Deploy ${props.website.domain}` });
        notice.value = data.message || `${action} completed.`;
        window.setTimeout(() => window.location.reload(), 800);
    } catch (e) { error.value = e.message; } finally { busy.value = ''; progressText.value = ''; }
};
const connectAndDeploy = async () => {
    if (!canSave.value) return;
    if (!confirm(`Connect this repository and deploy ${form.value.branch} to ${props.website.domain}?\n\nFor the first deployment, the website root must be empty.`)) return;
    const saved = await save();
    if (saved) await run('clone', true);
};
const date = (value) => value ? new Date(value).toLocaleString() : 'Not scheduled';
const operationLabel = (action) => ({ clone: 'Deploy website', status: 'Check status', pull: 'Pull latest', push: 'Push changes', sync: 'Sync both ways' }[action] || action);
</script>

<template>
    <Head :title="`Git Deployment - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div><h1 class="text-lg font-semibold">Deploy from Git</h1><p class="text-sm text-slate-500">Publish code to {{ website.domain }} without using the terminal</p></div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700"><i class="bi bi-arrow-left mr-1"></i> Website dashboard</Link>
            </div>
        </template>

        <div class="mx-auto space-y-6 p-4 sm:p-6">
            <div v-if="notice" role="status" class="flex gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800"><i class="bi bi-check-circle-fill"></i><pre class="whitespace-pre-wrap font-sans">{{ notice }}</pre></div>
            <div v-if="error" role="alert" class="flex gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800"><i class="bi bi-exclamation-triangle-fill"></i><pre class="whitespace-pre-wrap font-sans">{{ error }}</pre></div>

            <div class="grid items-start gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
            <aside class="overflow-hidden rounded-xl border bg-white shadow-sm lg:sticky lg:top-6 dark:border-slate-700 dark:bg-slate-900">
                <div class="border-b p-4 dark:border-slate-700">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">Overview</p>
                    <div class="min-w-0"><div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :class="cloned ? 'bg-emerald-500' : deployment ? 'bg-amber-500' : 'bg-slate-300'"></span><p class="truncate font-medium">{{ form.repository_url || 'No repository connected' }}</p></div><p class="mt-1 text-xs text-slate-500">{{ cloned ? `${form.branch} branch is deployed` : deployment ? 'Connection saved — ready for first deploy' : 'Connect a repository to start' }}</p></div>
                    <dl class="mt-4 space-y-2 border-t pt-3 text-xs dark:border-slate-700"><div class="flex justify-between gap-3"><dt class="text-slate-500">Branch</dt><dd class="font-medium">{{ form.branch || '—' }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-500">Access</dt><dd class="capitalize">{{ accessType }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-500">Auto update</dt><dd class="capitalize">{{ form.auto_action }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-500">Next check</dt><dd class="text-right">{{ form.auto_action === 'off' ? 'Disabled' : date(deployment?.next_sync_at) }}</dd></div></dl>
                    <button v-if="deployment && !cloned" @click="run('clone')" :disabled="busy" class="mt-4 w-full rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"><i class="bi bi-rocket-takeoff mr-2"></i>Deploy now</button>
                    <button v-else-if="cloned" @click="run('pull')" :disabled="busy" class="mt-4 w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"><i class="bi bi-cloud-download mr-2"></i>Pull latest</button>
                </div>
                <nav class="grid grid-cols-5 p-2 lg:block" aria-label="Git deployment sections"><button v-for="tab in [{ id: 'connect', label: 'Connection', icon: 'bi-link-45deg' }, { id: 'deploy', label: 'Deployment', icon: 'bi-rocket-takeoff' }, { id: 'automation', label: 'Automation', icon: 'bi-clock-history' }, { id: 'yaml', label: 'GitHub YAML', icon: 'bi-filetype-yml' }, { id: 'activity', label: 'Activity', icon: 'bi-list-check' }]" :key="tab.id" @click="activePanel = tab.id" class="w-full rounded-lg px-1 py-2.5 text-[11px] font-medium lg:mb-1 lg:flex lg:items-center lg:px-3 lg:text-left lg:text-sm" :class="activePanel === tab.id ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800'"><i class="bi mr-1 lg:mr-3" :class="tab.icon"></i>{{ tab.label }}</button></nav>
            </aside>

            <main class="min-w-0">

            <section v-show="activePanel === 'connect'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-100 font-semibold text-indigo-700">1</span><div><h2 class="font-semibold">Connect your repository</h2><p class="mt-1 text-sm text-slate-500">GitHub, GitLab এবং Bitbucket HTTPS repository support করে।</p></div></div>
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <label class="md:col-span-2 text-sm font-medium">Repository URL <span class="text-red-500">*</span><input v-model.trim="form.repository_url" type="url" placeholder="https://github.com/username/project.git" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" :class="form.repository_url && !repoValid ? '!border-red-400' : ''" /><span v-if="form.repository_url && !repoValid" class="mt-1 block text-xs text-red-600">একটি valid GitHub, GitLab অথবা Bitbucket HTTPS URL দিন।</span><span v-else class="mt-1 block text-xs font-normal text-slate-500">Repository page থেকে HTTPS clone URL copy করুন।</span></label>
                    <label class="text-sm font-medium">Branch <span class="text-red-500">*</span><input v-model.trim="form.branch" placeholder="main" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" :class="form.branch && !branchValid ? '!border-red-400' : ''" /><span class="mt-1 block text-xs font-normal text-slate-500">সাধারণত <code>main</code> অথবা <code>master</code>।</span></label>
                    <fieldset><legend class="text-sm font-medium">Repository access</legend><div class="mt-1.5 grid grid-cols-2 rounded-lg bg-slate-100 p-1 dark:bg-slate-800"><button type="button" @click="accessType = 'public'" class="rounded-md px-3 py-2 text-sm" :class="accessType === 'public' ? 'bg-white font-medium shadow dark:bg-slate-700' : 'text-slate-500'">Public</button><button type="button" @click="accessType = 'private'" class="rounded-md px-3 py-2 text-sm" :class="accessType === 'private' ? 'bg-white font-medium shadow dark:bg-slate-700' : 'text-slate-500'">Private</button></div></fieldset>
                    <template v-if="accessType === 'private'">
                        <label class="text-sm font-medium">Git username<input v-model.trim="form.auth_username" placeholder="Your Git username" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" /></label>
                        <label class="text-sm font-medium">Personal access token <span class="text-red-500">*</span><div class="relative mt-1.5"><input v-model="form.auth_token" :type="showToken ? 'text' : 'password'" :placeholder="deployment?.has_token ? 'Token already saved — blank রাখলে আগেরটি থাকবে' : 'Paste access token'" autocomplete="new-password" class="w-full rounded-lg border-slate-300 pr-20 dark:bg-slate-800" /><button type="button" @click="showToken = !showToken" class="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs text-indigo-600">{{ showToken ? 'Hide' : 'Show' }}</button></div><span class="mt-1 block text-xs font-normal text-slate-500">Password নয়—repository read/write permission সহ access token দিন। Token encrypted থাকে।</span></label>
                    </template>
                </div>
                <div class="mt-6 flex flex-wrap items-center gap-3"><button v-if="!cloned" @click="connectAndDeploy" :disabled="!canSave" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40"><span v-if="busy" class="mr-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-white border-r-transparent"></span><i v-else class="bi bi-plug-fill mr-2"></i>{{ busy ? progressText : 'Connect & deploy' }}</button><button v-else @click="save" :disabled="!canSave" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-40">{{ busy === 'save' ? 'Saving…' : 'Update connection' }}</button><button v-if="!deployment && !cloned" @click="save" :disabled="!canSave" class="rounded-lg border border-indigo-300 px-4 py-2.5 text-sm font-medium text-indigo-700 disabled:opacity-40">Save only</button><span v-if="!canSave && !busy" class="text-xs text-slate-500">Required fields complete করলে button চালু হবে।</span><span v-if="deployment" class="text-sm text-emerald-600"><i class="bi bi-shield-check mr-1"></i>Settings saved securely</span></div>
            </section>

            <section v-show="activePanel === 'deploy'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-violet-100 font-semibold text-violet-700">2</span><div><h2 class="font-semibold">Deploy and manage</h2><p class="mt-1 text-sm text-slate-500">{{ actionHelp }}</p></div></div>
                <div v-if="busy && busy !== 'save'" class="mt-5 rounded-lg bg-indigo-50 p-4 text-sm text-indigo-700"><span class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-indigo-600 border-r-transparent"></span>{{ operationLabel(busy) }} চলছে—page বন্ধ করবেন না…</div>
                <div class="mt-5 flex flex-wrap gap-2">
                    <button v-if="!cloned" @click="run('clone')" :disabled="busy || !deployment" :title="!deployment ? 'Save repository connection first' : ''" class="rounded-lg bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-40"><i class="bi bi-rocket-takeoff mr-2"></i>{{ busy === 'clone' ? 'Deploying…' : 'Deploy website' }}</button>
                    <template v-else><button v-for="action in ['status','pull','push','sync']" :key="action" @click="run(action)" :disabled="busy" class="rounded-lg border px-4 py-2.5 text-sm font-medium hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800"><i class="bi mr-2" :class="{ 'bi-activity': action === 'status', 'bi-cloud-download': action === 'pull', 'bi-cloud-upload': action === 'push', 'bi-arrow-repeat': action === 'sync' }"></i>{{ operationLabel(action) }}</button></template>
                </div>
                <div v-if="!deployment" class="mt-3 text-xs text-amber-700"><i class="bi bi-lock mr-1"></i>Step 1 save না করা পর্যন্ত deployment disabled থাকবে।</div>
                <div class="mt-5 grid gap-3 rounded-lg bg-slate-50 p-4 text-xs text-slate-600 sm:grid-cols-3 dark:bg-slate-800/60 dark:text-slate-300"><p><i class="bi bi-check-circle mr-1 text-emerald-500"></i>Existing files silently delete হবে না</p><p><i class="bi bi-check-circle mr-1 text-emerald-500"></i>Conflicting changes auto-merge হবে না</p><p><i class="bi bi-check-circle mr-1 text-emerald-500"></i>প্রতিটি website-এর আলাদা lock</p></div>
            </section>

            <section v-show="activePanel === 'automation'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 font-semibold text-emerald-700">3</span><div><h2 class="font-semibold">Automatic updates</h2><p class="mt-1 text-sm text-slate-500">Optional—প্রথম deployment-এর পরে চালু করাই ভালো।</p></div></div>
                <div class="mt-5 grid gap-4 md:grid-cols-2"><label class="text-sm font-medium">What should happen automatically?<select v-model="form.auto_action" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800"><option value="off">Do nothing automatically (recommended first)</option><option value="pull">Pull latest code from repository</option><option value="push">Push server changes to repository</option><option value="sync">Pull, then push safely</option></select></label><label class="text-sm font-medium">Check every<select v-model.number="form.interval_minutes" :disabled="form.auto_action === 'off'" class="mt-1.5 w-full rounded-lg border-slate-300 disabled:opacity-50 dark:bg-slate-800"><option :value="5">5 minutes</option><option :value="15">15 minutes</option><option :value="30">30 minutes</option><option :value="60">1 hour</option><option :value="360">6 hours</option><option :value="1440">1 day</option></select></label></div>
                <button v-if="deployment" @click="save" :disabled="busy" class="mt-5 rounded-lg border border-indigo-300 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-40">Save automation</button>
                <p class="mt-3 text-xs text-slate-500">Next scheduled check: <strong>{{ date(deployment?.next_sync_at) }}</strong></p>
            </section>

            <section v-show="activePanel === 'yaml'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700"><i class="bi bi-filetype-yml"></i></span><div><h2 class="font-semibold">Deploy with GitHub Actions</h2><p class="mt-1 text-sm text-slate-500">GitHub-এ push করলেই project files সরাসরি <strong>{{ website.domain }}</strong>-এ deploy হবে।</p></div></div>
                    <button type="button" @click="copyYaml" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white dark:bg-indigo-600"><i class="bi mr-2" :class="yamlCopied ? 'bi-check-lg' : 'bi-copy'"></i>{{ yamlCopied ? 'Copied' : 'Copy YAML' }}</button>
                </div>

                <ol class="mt-5 grid gap-3 text-sm md:grid-cols-3">
                    <li class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><strong>1. Workflow file</strong><p class="mt-1 text-xs text-slate-500">Repository-তে <code>.github/workflows/deploy.yml</code> তৈরি করে নিচের code paste করুন।</p></li>
                    <li class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><strong>2. GitHub Secrets</strong><p class="mt-1 text-xs text-slate-500">Settings → Secrets and variables → Actions-এ secretগুলো যোগ করুন।</p></li>
                    <li class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><strong>3. Push</strong><p class="mt-1 text-xs text-slate-500"><code>{{ form.branch || 'main' }}</code> branch-এ push করলে deployment চলবে।</p></li>
                </ol>

                <div class="mt-5 overflow-hidden rounded-lg border border-slate-700 bg-slate-950">
                    <div class="flex items-center justify-between border-b border-slate-700 px-4 py-2 text-xs text-slate-400"><span>.github/workflows/deploy.yml</span><span>{{ website.root_path }}</span></div>
                    <pre class="max-h-[34rem] overflow-auto p-4 text-xs leading-5 text-slate-200"><code>{{ githubActionsYaml }}</code></pre>
                </div>

                <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    <p class="font-semibold"><i class="bi bi-key mr-2"></i>Required repository secrets</p>
                    <div class="mt-2 flex flex-wrap gap-2"><code v-for="name in ['SSH_HOST', 'SSH_USER', 'SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS']" :key="name" class="rounded bg-white/70 px-2 py-1 dark:bg-slate-900">{{ name }}</code><code class="rounded bg-white/70 px-2 py-1 dark:bg-slate-900">SSH_PORT (optional)</code></div>
                    <p class="mt-3 text-xs">SSH user-এর <code>{{ website.root_path }}</code> path-এ write permission থাকতে হবে। Workflow <code>.env</code>, <code>storage</code>, <code>vendor</code> এবং <code>node_modules</code> পরিবর্তন করবে না।</p>
                </div>
            </section>

            <section v-show="activePanel === 'activity'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-4"><div><h2 class="font-semibold">Deployment activity</h2><p class="text-xs text-slate-500">সর্বশেষ ৩০টি operation এবং error এখানে দেখা যাবে।</p></div><button v-if="deployment" @click="run('status')" :disabled="busy" class="rounded-lg border px-3 py-2 text-xs dark:border-slate-700"><i class="bi bi-arrow-clockwise mr-1"></i>Refresh status</button></div>
                <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">Time</th><th>Operation</th><th>Result</th><th>Details</th></tr></thead><tbody><tr v-for="log in logs" :key="log.id" class="border-t align-top dark:border-slate-800"><td class="py-3 pr-4 whitespace-nowrap">{{ date(log.created_at) }}</td><td class="pr-4">{{ operationLabel(log.action) }}</td><td class="pr-4"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="log.status === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">{{ log.status === 'success' ? 'Successful' : 'Needs attention' }}</span></td><td class="max-w-xl whitespace-pre-wrap break-words text-xs text-slate-600 dark:text-slate-300">{{ log.message }}</td></tr><tr v-if="!logs.length"><td colspan="4" class="py-10 text-center"><i class="bi bi-clock-history block text-2xl text-slate-300"></i><p class="mt-2 text-slate-500">No deployment activity yet</p><p class="text-xs text-slate-400">Connection save করে প্রথম deployment শুরু করুন।</p></td></tr></tbody></table></div>
            </section>
            </main>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
