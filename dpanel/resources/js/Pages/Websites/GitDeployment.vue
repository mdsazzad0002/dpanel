<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({ website: Object, deployment: Object, repositoryConnected: Boolean, logs: { type: Array, default: () => [] }, github: { type: Object, default: () => ({ configured: false, accounts: [] }) }, needsResolution: Boolean, aiAvailable: Boolean, resolutionPending: Boolean, detectedCheckout: { type: Object, default: null }, access: { type: Object, default: null } });
const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);
const csrf = computed(() => document.querySelector('meta[name="csrf-token"]')?.content || '');
const form = ref({ repository_url: props.deployment?.repository_url || '', repository_full_name: props.deployment?.repository_full_name || '', branch: props.deployment?.branch || 'main', auth_username: props.deployment?.auth_username || '', auth_token: '', auto_action: props.deployment?.auto_action || 'off', interval_minutes: props.deployment?.interval_minutes || 15, enabled: props.deployment?.enabled ?? true });
const deployment = ref(props.deployment);
const logs = ref(props.logs);
const repositoryConnected = ref(props.repositoryConnected);
const accessType = ref(props.deployment?.has_token ? 'private' : 'public');
const showToken = ref(false);
const busy = ref('');
// Boolean view of busy: Vue renders :disabled="''" as disabled, so never bind busy directly.
const isBusy = computed(() => Boolean(busy.value));
const progressText = ref('');
const activePanel = ref(props.deployment ? 'deploy' : 'connect');
const notice = ref('');
const error = ref('');
const yamlCopied = ref(false);
const confirmingDisconnect = ref(false);
const flash = computed(() => page.props.flash || {});

// Source: a connected GitHub account (OAuth token, repo/branch pickers) or a
// manually typed URL with an optional personal access token.
const githubAccounts = computed(() => {
    const own = props.github?.accounts || [];
    const linked = deployment.value?.github_account;
    return linked && !own.some((account) => account.id === linked.id) ? [...own, { ...linked, foreign: true }] : own;
});
// A deployment with a GitHub repo name but no account lost its account on disconnect.
const accountUnlinked = computed(() => Boolean(deployment.value?.repository_full_name && !deployment.value?.github_account_id && !deployment.value?.has_token));
const source = ref(props.deployment?.github_account_id || accountUnlinked.value || (!props.deployment && (props.github?.accounts || []).length) ? 'github' : 'url');
const accountId = ref(props.deployment?.github_account_id || props.github?.accounts?.[0]?.id || '');
const selectedAccount = computed(() => githubAccounts.value.find((account) => account.id === accountId.value) || null);
const repoSearch = ref('');
const repositories = ref([]);
const branches = ref([]);
const loadingRepos = ref(false);
const loadingBranches = ref(false);
const connectGithubUrl = computed(() => panelRoute('github.connect', { website: props.website.id }));
const filteredRepos = computed(() => {
    const needle = repoSearch.value.trim().toLowerCase();
    return repositories.value.filter((repo) => !needle || repo.full_name.toLowerCase().includes(needle)).slice(0, 50);
});
const getJson = async (url) => {
    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || t('websites.git.requestFailed'));
    return data;
};
const loadRepositories = async () => {
    if (!selectedAccount.value || selectedAccount.value.foreign) { repositories.value = []; return; }
    loadingRepos.value = true; error.value = '';
    try {
        const pages = await Promise.all([1, 2, 3].map((page) => getJson(panelRoute('github.accounts.repositories', { account: accountId.value, page }))));
        repositories.value = pages.flatMap((data) => data.repositories || []);
    } catch (e) { error.value = e.message; repositories.value = []; } finally { loadingRepos.value = false; }
};
const loadBranches = async (fullName) => {
    branches.value = [];
    if (!fullName || !selectedAccount.value || selectedAccount.value.foreign) return;
    loadingBranches.value = true;
    try {
        branches.value = (await getJson(`${panelRoute('github.accounts.branches', { account: accountId.value })}?repository=${encodeURIComponent(fullName)}`)).branches || [];
    } catch (e) { error.value = e.message; } finally { loadingBranches.value = false; }
};
const pickRepository = (repo) => {
    form.value.repository_url = repo.clone_url;
    form.value.repository_full_name = repo.full_name;
    form.value.branch = repo.default_branch || 'main';
    repoSearch.value = '';
    loadBranches(repo.full_name);
};
const changeAccount = () => { repositories.value = []; branches.value = []; loadRepositories(); };
const useSource = (value) => {
    source.value = value;
    if (value === 'github' && !repositories.value.length) loadRepositories();
};
if (source.value === 'github' && props.github?.configured) {
    loadRepositories();
    if (props.deployment?.repository_full_name) loadBranches(props.deployment.repository_full_name);
}

const repoValid = computed(() => /^https:\/\/(github\.com|gitlab\.com|bitbucket\.org)\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+(?:\.git)?\/?$/i.test(form.value.repository_url));
const branchValid = computed(() => /^[A-Za-z0-9._/-]+$/.test(form.value.branch) && !form.value.branch.includes('..'));
const credentialsReady = computed(() => source.value === 'github' ? Boolean(selectedAccount.value) && /^https:\/\/github\.com\//i.test(form.value.repository_url) : accessType.value === 'public' || Boolean(form.value.auth_token || deployment.value?.has_token));
const canSave = computed(() => repoValid.value && branchValid.value && credentialsReady.value && !busy.value);
const repoChanged = computed(() => Boolean(deployment.value) && (form.value.repository_url !== deployment.value.repository_url || form.value.branch !== deployment.value.branch));
const accountChanged = computed(() => Boolean(deployment.value) && (source.value === 'github' ? accountId.value : null) !== (deployment.value.github_account_id || null));
const cloned = computed(() => repositoryConnected.value || logs.value.some((log) => ['clone', 'init'].includes(log.action) && log.status === 'success'));
const currentStep = computed(() => !deployment.value ? 1 : !cloned.value ? 2 : 3);
// Probe the repository as soon as it's chosen: a remote with code gets
// deployed (clone), an empty remote gets this website's files pushed (init).
const probeState = ref(null);
const probing = ref(false);
const probeError = ref('');
const firstAction = computed(() => probeState.value?.recommended || null);
let probeTimer = null;
let probeSeq = 0;
const probeRepository = async () => {
    const seq = ++probeSeq;
    probeState.value = null; probeError.value = '';
    if (!repoValid.value || !branchValid.value || (cloned.value && !repoChanged.value)) { probing.value = false; return; }
    const payload = { repository_url: form.value.repository_url, branch: form.value.branch };
    if (source.value === 'github') {
        if (!selectedAccount.value) { probing.value = false; return; }
        payload.github_account_id = accountId.value;
    } else if (accessType.value === 'private' && form.value.auth_token) {
        payload.auth_username = form.value.auth_username;
        payload.auth_token = form.value.auth_token;
    }
    probing.value = true;
    try {
        const data = await request(panelRoute('websites.git.probe', { id: props.website.id }), 'POST', payload);
        if (seq === probeSeq) probeState.value = data;
    } catch (e) {
        if (seq === probeSeq) probeError.value = e.message;
    } finally {
        if (seq === probeSeq) probing.value = false;
    }
};
watch(
    () => [form.value.repository_url, form.value.branch, source.value, accountId.value, accessType.value, form.value.auth_token, cloned.value],
    () => { window.clearTimeout(probeTimer); probing.value = true; probeTimer = window.setTimeout(probeRepository, 600); },
    { immediate: true },
);
const decision = computed(() => {
    const state = probeState.value;
    if (!state) return null;
    if (!state.remote_empty) {
        return state.branch_exists
            ? { tone: 'violet', icon: 'bi-cloud-download', title: t('websites.git.decision.cloneTitle'), text: t('websites.git.decision.cloneText', { branch: form.value.branch }) }
            : { tone: 'amber', icon: 'bi-exclamation-triangle', title: t('websites.git.decision.noBranchTitle', { branch: form.value.branch }), text: t('websites.git.decision.noBranchText') };
    }
    if (state.local_repo) return { tone: 'amber', icon: 'bi-exclamation-triangle', title: t('websites.git.decision.localRepoTitle'), text: t('websites.git.decision.localRepoText') };
    if (state.local_files) return { tone: 'emerald', icon: 'bi-cloud-upload', title: t('websites.git.decision.pushTitle'), text: t('websites.git.decision.pushText', { branch: form.value.branch }) };
    return { tone: 'slate', icon: 'bi-info-circle', title: t('websites.git.decision.emptyTitle'), text: t('websites.git.decision.emptyText') };
});
const canFirstAction = computed(() => firstAction.value === 'init' || (firstAction.value === 'clone' && probeState.value?.branch_exists));
const decisionClass = (tone) => ({
    violet: 'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-800 dark:bg-violet-950/30 dark:text-violet-200',
    emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200',
    amber: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200',
    slate: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-200',
}[tone]);

const actionHelp = computed(() => {
    if (!deployment.value) return t('websites.git.help.save');
    if (!cloned.value && firstAction.value === 'init') return t('websites.git.help.init');
    if (!cloned.value && firstAction.value === 'clone') return t('websites.git.help.clone');
    if (!cloned.value) return t('websites.git.help.checking');
    return t('websites.git.help.connected');
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
        error.value = t('websites.git.yaml.copyFailed');
    }
};

const request = async (url, method, body) => {
    const response = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf.value, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw Object.assign(new Error(data.message || Object.values(data.errors || {}).flat().join(' ') || t('websites.git.requestFailed')), { data });
    return data;
};
// Apply the deployment state an operation returns, so the page updates in place.
const applyState = (data) => {
    if (data && 'access' in data) access.value = data.access;
    if (!data?.deployment) return;
    deployment.value = data.deployment;
    repositoryConnected.value = Boolean(data.repositoryConnected);
    logs.value = data.logs || [];
    needsResolution.value = Boolean(data.needsResolution);
    form.value.branch = data.deployment.branch;
    targetBranch.value = data.deployment.branch;
};
// Users other than the one who connected the repository (admins aside) link
// their own GitHub account or token before Git actions unlock for them.
const access = ref(props.access);
const locked = computed(() => Boolean(deployment.value && access.value && !access.value.connected));
const ownAccounts = computed(() => props.github?.accounts || []);
const repoOnGithub = computed(() => /^https:\/\/github\.com\//i.test(deployment.value?.repository_url || ''));
const linkMode = ref(repoOnGithub.value && props.github?.configured ? 'github' : 'token');
const linkAccountId = ref(ownAccounts.value[0]?.id || '');
const linkUsername = ref('');
const linkToken = ref('');
const canLink = computed(() => !isBusy.value && (linkMode.value === 'github' ? Boolean(linkAccountId.value) : Boolean(linkToken.value.trim())));
const linkAccount = async () => {
    if (!canLink.value) return;
    busy.value = 'link'; notice.value = ''; error.value = '';
    const payload = linkMode.value === 'github'
        ? { github_account_id: linkAccountId.value }
        : { auth_username: linkUsername.value, auth_token: linkToken.value };
    try {
        const data = await request(panelRoute('websites.git.link', { id: props.website.id }), 'POST', payload);
        applyState(data);
        linkToken.value = '';
        (data.success ? notice : error).value = data.message;
        activePanel.value = 'deploy';
    } catch (e) { applyState(e.data); error.value = e.message; } finally { busy.value = ''; }
};
const unlinkAccount = async () => {
    if (!confirm(t('websites.git.unlinkConfirm'))) return;
    busy.value = 'unlink'; notice.value = ''; error.value = '';
    try {
        const data = await request(panelRoute('websites.git.unlink', { id: props.website.id }), 'DELETE', {});
        applyState(data);
        notice.value = data.message;
    } catch (e) { error.value = e.message; } finally { busy.value = ''; }
};
// The website root already holds a checkout (copied in by a clone, or made
// by hand): connect it as it is instead of cloning again.
const detectedCheckout = ref(props.detectedCheckout);
const connectDetected = async () => {
    busy.value = 'detect'; notice.value = ''; error.value = '';
    try {
        const data = await request(panelRoute('websites.git.detect', { id: props.website.id }), 'POST', {});
        applyState(data);
        form.value.repository_url = data.deployment.repository_url;
        form.value.repository_full_name = data.deployment.repository_full_name || '';
        detectedCheckout.value = null;
        notice.value = data.message;
        activePanel.value = 'deploy';
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = '';
    }
};
const save = async () => {
    if (!canSave.value) return false;
    busy.value = 'save'; progressText.value = t('websites.git.saving'); notice.value = ''; error.value = '';
    const payload = { ...form.value };
    if (source.value === 'github') {
        payload.github_account_id = accountId.value;
        payload.auth_username = ''; payload.auth_token = '';
    } else {
        payload.github_account_id = null;
        payload.repository_full_name = null;
        payload.clear_token = accessType.value === 'public';
        if (accessType.value === 'public') { payload.auth_username = ''; payload.auth_token = ''; }
    }
    try {
        const data = await request(panelRoute('websites.git.store', { id: props.website.id }), 'PUT', payload);
        deployment.value = data.deployment; form.value.auth_token = '';
        if (data.repository_changed) { repositoryConnected.value = false; logs.value = []; }
        notice.value = t('websites.git.saved');
        activePanel.value = 'deploy';
        return true;
    } catch (e) { error.value = e.message; return false; } finally { busy.value = ''; progressText.value = ''; }
};
const run = async (action, skipConfirmation = false, extra = {}) => {
    if (!deployment.value) { error.value = t('websites.git.saveFirst'); return; }
    if (action === 'clone' && !skipConfirmation && !confirm(t('websites.git.confirm.clone', { branch: form.value.branch, domain: props.website.domain }))) return;
    if (action === 'init' && !skipConfirmation && !confirm(t('websites.git.confirm.init', { branch: form.value.branch, domain: props.website.domain }))) return;
    busy.value = action; progressText.value = action === 'clone' ? t('websites.git.progress.clone') : action === 'init' ? t('websites.git.progress.init') : t('websites.git.progress.other', { action: operationLabel(action) }); notice.value = ''; error.value = '';
    try {
        const data = await request(panelRoute('websites.git.run', { id: props.website.id }), 'POST', { action, message: `Deploy ${props.website.domain}`, ...extra });
        applyState(data);
        notice.value = data.message || t('websites.git.completed', { action: operationLabel(action) });
    } catch (e) { applyState(e.data); error.value = e.message; } finally { busy.value = ''; progressText.value = ''; }
};
const targetBranch = ref(props.deployment?.branch || '');
const switchBranch = async () => {
    const branch = targetBranch.value.trim();
    if (!branch || branch === deployment.value?.branch) return;
    if (!/^[A-Za-z0-9._/-]+$/.test(branch) || branch.includes('..')) { error.value = t('websites.git.invalidBranch'); return; }
    if (!confirm(t('websites.git.confirm.switch', { domain: props.website.domain, from: deployment.value.branch, to: branch }))) return;
    await run('checkout', true, { branch });
};
const webhookSecret = ref('');
const togglePushDeploy = async (enabled, rotate = false) => {
    busy.value = 'webhook'; notice.value = ''; error.value = ''; webhookSecret.value = '';
    try {
        const data = await request(panelRoute('websites.git.webhook', { id: props.website.id }), 'PUT', { enabled, rotate });
        deployment.value = data.deployment;
        webhookSecret.value = data.webhook_secret || '';
        notice.value = data.message;
    } catch (e) { error.value = e.message; } finally { busy.value = ''; }
};
const reclone = async () => {
    if (!confirm(t('websites.git.confirm.reclone', { branch: form.value.branch, domain: props.website.domain }))) return;
    await run('clone', true);
};
const connectAndDeploy = async () => {
    if (!canSave.value || !canFirstAction.value) return;
    const pushing = firstAction.value === 'init';
    const question = pushing
        ? t('websites.git.confirm.connectPush', { branch: form.value.branch, domain: props.website.domain })
        : t('websites.git.confirm.connectClone', { branch: form.value.branch, domain: props.website.domain });
    if (!confirm(question)) return;
    const saved = await save();
    if (saved) await run(pushing ? 'init' : 'clone', true);
};
const disconnect = async () => {
    if (!deployment.value) return;
    confirmingDisconnect.value = false;
    busy.value = 'disconnect'; notice.value = ''; error.value = '';
    try {
        const data = await request(panelRoute('websites.git.destroy', { id: props.website.id }), 'DELETE', {});
        deployment.value = null; repositoryConnected.value = false; logs.value = [];
        form.value = { repository_url: '', repository_full_name: '', branch: 'main', auth_username: '', auth_token: '', auto_action: 'off', interval_minutes: 15, enabled: true };
        accessType.value = 'public';
        activePanel.value = 'connect';
        notice.value = data.message || t('websites.git.disconnected');
    } catch (e) { error.value = e.message; } finally { busy.value = ''; }
};
// Conflicts: the live site (server) and the repository (code pushed from a
// developer's local copy) both changed. Settle with Live / Local / Both, or
// per file, optionally with AI-written merges.
const needsResolution = ref(props.needsResolution);
const conflictState = ref(null);
const choices = ref({});
const aiSuggestions = ref({});
const aiSkipped = ref({});
const viewing = ref({});
const sides = computed(() => [
    { id: 'live', label: t('websites.git.side.live'), hint: t('websites.git.side.liveHint') },
    { id: 'remote', label: t('websites.git.side.remote'), hint: 'GitHub' },
    { id: 'both', label: t('websites.git.side.both'), hint: t('websites.git.side.bothHint') },
]);
const checkConflicts = async () => {
    busy.value = 'conflicts'; error.value = ''; notice.value = '';
    try {
        const data = await request(panelRoute('websites.git.conflicts', { id: props.website.id }), 'POST', {});
        conflictState.value = data;
        aiSuggestions.value = {}; aiSkipped.value = {};
        choices.value = Object.fromEntries(data.conflicts.map((file) => [file.path, 'live']));
        viewing.value = Object.fromEntries(data.conflicts.map((file) => [file.path, file.kind === 'text' ? 'merged' : 'live']));
        if (!data.behind) notice.value = t('websites.git.conflicts.nothingNew');
    } catch (e) { error.value = e.message; } finally { busy.value = ''; }
};
const askAi = async () => {
    busy.value = 'ai'; error.value = ''; notice.value = '';
    try {
        const data = await request(panelRoute('websites.git.conflicts.ai', { id: props.website.id }), 'POST', {});
        conflictState.value = { ...(conflictState.value || {}), conflicts: data.conflicts };
        aiSuggestions.value = data.suggestions || {};
        aiSkipped.value = data.skipped || {};
        data.conflicts.forEach((file) => {
            if (aiSuggestions.value[file.path] !== undefined) { choices.value[file.path] = 'ai'; viewing.value[file.path] = 'ai'; }
            else choices.value[file.path] ||= 'live';
        });
        const count = Object.keys(aiSuggestions.value).length;
        notice.value = count ? t('websites.git.conflicts.aiProposed', { count }) : t('websites.git.conflicts.aiFailed');
    } catch (e) { error.value = e.message; } finally { busy.value = ''; }
};
const resolveConflicts = async (strategy) => {
    const files = conflictState.value?.conflicts || [];
    const labels = { live: t('websites.git.keep.live'), remote: t('websites.git.keep.remote'), both: t('websites.git.keep.both'), custom: t('websites.git.keep.custom') };
    if (!confirm(t('websites.git.confirm.merge', { branch: form.value.branch, domain: props.website.domain, keep: labels[strategy] }))) return;
    const payload = { strategy };
    if (strategy === 'custom') {
        payload.resolutions = files.map((file) => choices.value[file.path] === 'ai'
            ? { path: file.path, content: aiSuggestions.value[file.path] }
            : { path: file.path, take: choices.value[file.path] || 'live' });
    }
    busy.value = 'resolve'; error.value = ''; notice.value = '';
    try {
        const data = await request(panelRoute('websites.git.resolve', { id: props.website.id }), 'POST', payload);
        applyState(data);
        conflictState.value = null; needsResolution.value = false;
        notice.value = data.message;
        await loadPending();
    } catch (e) { applyState(e.data); error.value = e.message; } finally { if (busy.value === 'resolve') busy.value = ''; }
};
// The resolved merge waits here until it is pushed or undone.
const pendingPush = ref(null);
const loadPending = async () => {
    try {
        const data = await request(panelRoute('websites.git.resolve.pending', { id: props.website.id }), 'POST', {});
        pendingPush.value = data.pending ? data : null;
    } catch (e) { error.value = e.message; }
};
const finishResolution = async (decision) => {
    const count = pendingPush.value?.files?.length || 0;
    const question = decision === 'confirm'
        ? t('websites.git.confirm.pushMerge', { branch: form.value.branch, count })
        : t('websites.git.confirm.undoMerge');
    if (!confirm(question)) return;
    busy.value = decision === 'confirm' ? 'confirm_push' : 'undo_resolve'; error.value = ''; notice.value = '';
    try {
        const data = await request(panelRoute('websites.git.resolve.finish', { id: props.website.id }), 'POST', { decision });
        applyState(data);
        pendingPush.value = null;
        notice.value = data.message;
    } catch (e) { applyState(e.data); error.value = e.message; } finally { busy.value = ''; }
};
if (props.resolutionPending) loadPending();
const fileStatus = (status) => ({ A: 'added', M: 'changed', D: 'deleted' }[status?.[0]] || status);
const fileStatusLabel = (status) => ({ added: t('websites.git.file.added'), changed: t('websites.git.file.changed'), deleted: t('websites.git.file.deleted') }[fileStatus(status)] || status);
const versionText = (file) => ({
    merged: file.merged,
    live: file.live_deleted ? t('websites.git.file.deletedServer') : file.live,
    remote: file.remote_deleted ? t('websites.git.file.deletedRepo') : file.remote,
    base: file.base,
    ai: aiSuggestions.value[file.path],
}[viewing.value[file.path]] ?? (file.kind === 'binary' ? t('websites.git.file.binary') : file.kind === 'too_large' ? t('websites.git.file.tooLarge') : ''));
const date = (value) => value ? new Date(value).toLocaleString() : t('websites.git.notScheduled');
const OPERATIONS = ['clone', 'init', 'status', 'pull', 'push', 'sync', 'checkout', 'webhook', 'conflicts', 'ai', 'resolve', 'confirm_push', 'undo_resolve', 'link'];
const operationLabel = (action) => (OPERATIONS.includes(action) ? t(`websites.git.op.${action}`) : action);
</script>

<template>
    <Head :title="t('websites.git.headTitle', { domain: website.domain })" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div><h1 class="text-lg font-semibold">{{ t('websites.git.title') }}</h1><p class="text-sm text-slate-500">{{ t('websites.git.subtitle', { domain: website.domain }) }}</p></div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700"><i class="bi bi-arrow-left mr-1"></i> {{ t('websites.git.back') }}</Link>
            </div>
        </template>

        <div class="mx-auto space-y-6 p-4 sm:p-6">
            <div v-if="flash.success && !notice" role="status" class="flex gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800"><i class="bi bi-check-circle-fill"></i><span>{{ flash.success }}</span></div>
            <div v-if="flash.error && !error" role="alert" class="flex gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800"><i class="bi bi-exclamation-triangle-fill"></i><span>{{ flash.error }}</span></div>
            <div v-if="notice" role="status" class="flex gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800"><i class="bi bi-check-circle-fill"></i><pre class="whitespace-pre-wrap font-sans">{{ notice }}</pre></div>
            <div v-if="error" role="alert" class="flex gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800"><i class="bi bi-exclamation-triangle-fill"></i><pre class="whitespace-pre-wrap font-sans">{{ error }}</pre></div>

            <div class="grid items-start gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
            <aside class="overflow-hidden rounded-xl border bg-white shadow-sm lg:sticky lg:top-6 dark:border-slate-700 dark:bg-slate-900">
                <div class="border-b p-4 dark:border-slate-700">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ t('websites.git.overview.title') }}</p>
                    <div class="min-w-0"><div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :class="cloned ? 'bg-emerald-500' : deployment ? 'bg-amber-500' : 'bg-slate-300'"></span><p class="truncate font-medium">{{ form.repository_url || t('websites.git.overview.noRepo') }}</p></div><p class="mt-1 text-xs text-slate-500">{{ cloned ? t('websites.git.overview.deployed', { branch: form.branch }) : deployment ? t('websites.git.overview.ready') : t('websites.git.overview.start') }}</p></div>
                    <dl class="mt-4 space-y-2 border-t pt-3 text-xs dark:border-slate-700"><div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('websites.git.branch') }}</dt><dd class="font-medium">{{ form.branch || '—' }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('websites.git.overview.access') }}</dt><dd class="truncate" :class="source === 'github' ? '' : 'capitalize'">{{ source === 'github' ? (selectedAccount ? `@${selectedAccount.login}` : 'GitHub') : (accessType === 'private' ? t('websites.git.private') : t('websites.git.public')) }}</dd></div><div v-if="access?.via === 'link'" class="flex items-center justify-between gap-3"><dt class="text-slate-500">{{ t('websites.git.overview.workAs') }}</dt><dd class="flex min-w-0 items-center gap-1.5"><span class="truncate">{{ access.login ? `@${access.login}` : t('websites.git.overview.yourToken') }}</span><button type="button" @click="unlinkAccount" :disabled="isBusy" class="text-red-600 hover:underline disabled:opacity-40" :title="t('websites.git.overview.unlinkTitle')">{{ t('websites.git.overview.unlink') }}</button></dd></div><div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('websites.git.overview.autoUpdate') }}</dt><dd>{{ t(`websites.git.overview.auto.${form.auto_action}`) }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('websites.git.overview.nextCheck') }}</dt><dd class="text-right">{{ form.auto_action === 'off' ? t('websites.git.overview.disabled') : date(deployment?.next_sync_at) }}</dd></div></dl>
                    <button v-if="deployment && !locked && !cloned && canFirstAction" @click="run(firstAction)" :disabled="isBusy" class="mt-4 w-full rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"><i class="bi mr-2" :class="firstAction === 'init' ? 'bi-cloud-upload' : 'bi-rocket-takeoff'"></i>{{ firstAction === 'init' ? t('websites.git.pushNow') : t('websites.git.deployNow') }}</button>
                    <button v-else-if="cloned && !locked && !repoChanged" @click="run('pull')" :disabled="isBusy" class="mt-4 w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"><i class="bi bi-cloud-download mr-2"></i>{{ t('websites.git.op.pull') }}</button>
                </div>
                <nav class="grid grid-cols-5 p-2 lg:block" :aria-label="t('websites.git.tabs.label')"><button v-for="tab in [{ id: 'connect', label: t('websites.git.tabs.connect'), icon: 'bi-link-45deg' }, { id: 'deploy', label: t('websites.git.tabs.deploy'), icon: 'bi-rocket-takeoff' }, { id: 'automation', label: t('websites.git.tabs.automation'), icon: 'bi-clock-history' }, { id: 'yaml', label: 'GitHub YAML', icon: 'bi-filetype-yml' }, { id: 'activity', label: t('websites.git.tabs.activity'), icon: 'bi-list-check' }]" :key="tab.id" @click="activePanel = tab.id" class="w-full rounded-lg px-1 py-2.5 text-[11px] font-medium lg:mb-1 lg:flex lg:items-center lg:px-3 lg:text-left lg:text-sm" :class="activePanel === tab.id ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800'"><i class="bi mr-1 lg:mr-3" :class="tab.icon"></i>{{ tab.label }}</button></nav>
            </aside>

            <main class="min-w-0">

            <section v-if="locked" class="rounded-xl border border-indigo-200 bg-white p-5 shadow-sm dark:border-indigo-900 dark:bg-slate-900">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300"><i class="bi bi-person-lock text-lg"></i></span>
                    <div class="min-w-0">
                        <h2 class="font-semibold">{{ t('websites.git.link.title') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ access?.connected_by ? t('websites.git.link.helpBy', { name: access.connected_by }) : t('websites.git.link.help') }}</p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800/60">
                    <span class="flex min-w-0 items-center gap-2"><i class="bi bi-journal-code text-indigo-600"></i><span class="truncate font-medium">{{ deployment.repository_full_name || deployment.repository_url }}</span></span>
                    <span class="text-slate-500"><i class="bi bi-git mr-1"></i>{{ deployment.branch }}</span>
                </div>

                <div v-if="repoOnGithub && github.configured" class="mt-4 grid grid-cols-2 rounded-lg bg-slate-100 p-1 sm:max-w-md dark:bg-slate-800"><button type="button" @click="linkMode = 'github'" class="rounded-md px-3 py-2 text-sm" :class="linkMode === 'github' ? 'bg-white font-medium shadow dark:bg-slate-700' : 'text-slate-500'"><i class="bi bi-github mr-1"></i>{{ t('websites.git.githubAccount') }}</button><button type="button" @click="linkMode = 'token'" class="rounded-md px-3 py-2 text-sm" :class="linkMode === 'token' ? 'bg-white font-medium shadow dark:bg-slate-700' : 'text-slate-500'"><i class="bi bi-key mr-1"></i>{{ t('websites.git.accessToken') }}</button></div>

                <div v-if="linkMode === 'github'" class="mt-4">
                    <div v-if="!ownAccounts.length" class="rounded-lg border border-dashed p-5 text-center dark:border-slate-700">
                        <i class="bi bi-github text-3xl text-slate-400"></i>
                        <p class="mt-2 text-sm text-slate-500">{{ t('websites.git.link.noAccount') }}</p>
                        <Link :href="connectGithubUrl" class="mt-3 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-indigo-600"><i class="bi bi-plus-lg mr-1"></i>{{ t('websites.git.connectGithub') }}</Link>
                    </div>
                    <label v-else class="block text-sm font-medium sm:max-w-md">{{ t('websites.git.link.yourAccount') }}
                        <div class="mt-1.5 flex gap-2"><select v-model="linkAccountId" class="min-w-0 flex-1 rounded-lg border-slate-300 dark:bg-slate-800"><option v-for="account in ownAccounts" :key="account.id" :value="account.id">@{{ account.login }}</option></select><Link :href="connectGithubUrl" class="shrink-0 rounded-lg border px-3 py-2 text-sm dark:border-slate-700" :title="t('websites.git.connectAnother')"><i class="bi bi-plus-lg"></i></Link></div>
                    </label>
                </div>
                <div v-else class="mt-4 grid gap-4 md:grid-cols-2">
                    <label class="text-sm font-medium">{{ t('websites.git.username') }}<input v-model.trim="linkUsername" :placeholder="t('websites.git.usernamePlaceholder')" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" /></label>
                    <label class="text-sm font-medium">{{ t('websites.git.pat') }} <span class="text-red-500">*</span><input v-model="linkToken" type="password" autocomplete="new-password" :placeholder="t('websites.git.pastePat')" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" /><span class="mt-1 block text-xs font-normal text-slate-500">{{ t('websites.git.link.tokenHelp') }}</span></label>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button type="button" @click="linkAccount" :disabled="!canLink" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40">
                        <span v-if="busy === 'link'" class="mr-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-white border-r-transparent"></span><i v-else class="bi bi-plug-fill mr-2"></i>{{ busy === 'link' ? t('websites.git.link.connecting') : (cloned ? t('websites.git.link.connectPull') : t('websites.git.link.connect')) }}
                    </button>
                    <span class="text-xs text-slate-500">{{ t('websites.git.link.after') }}</span>
                </div>
            </section>

            <template v-else>

            <section v-show="activePanel === 'connect'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-100 font-semibold text-indigo-700">1</span><div><h2 class="font-semibold">{{ t('websites.git.connect.title') }}</h2><p class="mt-1 text-sm text-slate-500">{{ t('websites.git.connect.help') }}</p></div></div>
                <div v-if="!deployment && detectedCheckout" class="mt-5 flex flex-wrap items-center gap-3 rounded-lg border border-orange-200 bg-orange-50 p-4 text-sm text-orange-800 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-200">
                    <i class="bi bi-git text-lg"></i>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ t('websites.git.detected.title') }}</p>
                        <p class="mt-0.5 break-all text-xs"><code>{{ detectedCheckout.repository_url || detectedCheckout.remote }}</code> · {{ t('websites.git.branchLower') }} <code>{{ detectedCheckout.branch }}</code></p>
                        <p v-if="!detectedCheckout.repository_url" class="mt-1 text-xs text-red-600">{{ t('websites.git.detected.unsupported') }}</p>
                    </div>
                    <button v-if="detectedCheckout.repository_url" type="button" @click="connectDetected" :disabled="isBusy" class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700 disabled:opacity-40">
                        <i :class="['bi mr-1', busy === 'detect' ? 'bi-arrow-repeat inline-block animate-spin' : 'bi-plug-fill']"></i>{{ busy === 'detect' ? t('websites.git.connecting') : t('websites.git.detected.connect') }}
                    </button>
                </div>
                <div class="mt-5 grid grid-cols-2 rounded-lg bg-slate-100 p-1 sm:max-w-md dark:bg-slate-800"><button type="button" @click="useSource('github')" class="rounded-md px-3 py-2 text-sm" :class="source === 'github' ? 'bg-white font-medium shadow dark:bg-slate-700' : 'text-slate-500'"><i class="bi bi-github mr-1"></i>{{ t('websites.git.githubAccount') }}</button><button type="button" @click="useSource('url')" class="rounded-md px-3 py-2 text-sm" :class="source === 'url' ? 'bg-white font-medium shadow dark:bg-slate-700' : 'text-slate-500'"><i class="bi bi-link-45deg mr-1"></i>{{ t('websites.git.repoUrl') }}</button></div>

                <div v-if="source === 'github'" class="mt-5">
                    <div v-if="!github.configured" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800"><i class="bi bi-gear mr-1"></i><i18n-t keypath="websites.git.connect.notConfigured" scope="global"><template #link><Link :href="panelRoute('github.index')" class="font-medium underline">GitHub Integration</Link></template></i18n-t></div>
                    <div v-else-if="!githubAccounts.length" class="rounded-lg border border-dashed p-6 text-center dark:border-slate-700"><i class="bi bi-github text-3xl text-slate-400"></i><p class="mt-2 text-sm text-slate-500">{{ t('websites.git.connect.noAccount') }}</p><Link :href="connectGithubUrl" class="mt-3 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-indigo-600"><i class="bi bi-plus-lg mr-1"></i>{{ t('websites.git.connectGithub') }}</Link></div>
                    <div v-else class="grid gap-5 md:grid-cols-2">
                        <label class="text-sm font-medium">{{ t('websites.git.githubAccount') }}<div class="mt-1.5 flex gap-2"><select v-model="accountId" @change="changeAccount" class="min-w-0 flex-1 rounded-lg border-slate-300 dark:bg-slate-800"><option v-for="account in githubAccounts" :key="account.id" :value="account.id">@{{ account.login }}{{ account.foreign ? t('websites.git.connect.foreign') : '' }}</option></select><Link :href="connectGithubUrl" class="shrink-0 rounded-lg border px-3 py-2 text-sm dark:border-slate-700" :title="t('websites.git.connectAnother')"><i class="bi bi-plus-lg"></i></Link></div></label>
                        <label class="text-sm font-medium">{{ t('websites.git.branch') }} <span class="text-red-500">*</span>
                            <select v-if="branches.length" v-model="form.branch" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800"><option v-for="branch in branches" :key="branch" :value="branch">{{ branch }}</option></select>
                            <input v-else v-model.trim="form.branch" placeholder="main" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" />
                            <span class="mt-1 block text-xs font-normal text-slate-500">{{ loadingBranches ? t('websites.git.connect.loadingBranches') : t('websites.git.connect.branchHint') }}</span>
                        </label>
                        <div class="md:col-span-2">
                            <p class="text-sm font-medium">{{ t('websites.git.repository') }} <span class="text-red-500">*</span></p>
                            <div v-if="form.repository_url" class="mt-1.5 flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm dark:border-indigo-900 dark:bg-indigo-950/30"><i class="bi bi-journal-code text-indigo-600"></i><span class="min-w-0 flex-1 truncate font-medium">{{ form.repository_full_name || form.repository_url }}</span><button type="button" @click="form.repository_url = ''; form.repository_full_name = ''; branches = []" class="text-xs text-indigo-700">{{ t('websites.git.change') }}</button></div>
                            <template v-else>
                                <div class="relative mt-1.5"><i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i><input v-model="repoSearch" :placeholder="t('websites.git.connect.search')" class="w-full rounded-lg border-slate-300 pl-9 dark:bg-slate-800" /></div>
                                <div class="mt-2 max-h-72 overflow-y-auto rounded-lg border dark:border-slate-700">
                                    <p v-if="loadingRepos" class="p-4 text-center text-sm text-slate-500"><span class="mr-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-indigo-600 border-r-transparent"></span>{{ t('websites.git.connect.loadingRepos') }}</p>
                                    <p v-else-if="selectedAccount?.foreign" class="p-4 text-sm text-slate-500">{{ t('websites.git.connect.foreignHelp') }}</p>
                                    <template v-else>
                                        <button v-for="repo in filteredRepos" :key="repo.full_name" type="button" @click="pickRepository(repo)" class="flex w-full items-center gap-3 border-b px-3 py-2.5 text-left text-sm last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800"><i class="bi" :class="repo.private ? 'bi-lock text-amber-600' : 'bi-globe2 text-slate-400'"></i><span class="min-w-0 flex-1 truncate">{{ repo.full_name }}</span><span class="text-xs text-slate-400">{{ repo.default_branch }}</span></button>
                                        <p v-if="!filteredRepos.length" class="p-4 text-center text-sm text-slate-500">{{ t('websites.git.connect.noRepos') }} <button type="button" @click="loadRepositories" class="text-indigo-600 underline">{{ t('websites.git.connect.reload') }}</button></p>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <div v-else class="mt-5 grid gap-5 md:grid-cols-2">
                    <label class="md:col-span-2 text-sm font-medium">{{ t('websites.git.repoUrl') }} <span class="text-red-500">*</span><input v-model.trim="form.repository_url" type="url" placeholder="https://github.com/username/project.git" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" :class="form.repository_url && !repoValid ? '!border-red-400' : ''" /><span v-if="form.repository_url && !repoValid" class="mt-1 block text-xs text-red-600">{{ t('websites.git.connect.invalidUrl') }}</span><span v-else class="mt-1 block text-xs font-normal text-slate-500">{{ t('websites.git.connect.urlHint') }}</span></label>
                    <label class="text-sm font-medium">{{ t('websites.git.branch') }} <span class="text-red-500">*</span><input v-model.trim="form.branch" placeholder="main" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" :class="form.branch && !branchValid ? '!border-red-400' : ''" /><span class="mt-1 block text-xs font-normal text-slate-500"><i18n-t keypath="websites.git.connect.branchUsual" scope="global"><template #main><code>main</code></template><template #master><code>master</code></template></i18n-t></span></label>
                    <fieldset><legend class="text-sm font-medium">{{ t('websites.git.connect.access') }}</legend><div class="mt-1.5 grid grid-cols-2 rounded-lg bg-slate-100 p-1 dark:bg-slate-800"><button type="button" @click="accessType = 'public'" class="rounded-md px-3 py-2 text-sm" :class="accessType === 'public' ? 'bg-white font-medium shadow dark:bg-slate-700' : 'text-slate-500'">{{ t('websites.git.public') }}</button><button type="button" @click="accessType = 'private'" class="rounded-md px-3 py-2 text-sm" :class="accessType === 'private' ? 'bg-white font-medium shadow dark:bg-slate-700' : 'text-slate-500'">{{ t('websites.git.private') }}</button></div></fieldset>
                    <template v-if="accessType === 'private'">
                        <label class="text-sm font-medium">{{ t('websites.git.username') }}<input v-model.trim="form.auth_username" :placeholder="t('websites.git.usernamePlaceholder')" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" /></label>
                        <label class="text-sm font-medium">{{ t('websites.git.pat') }} <span class="text-red-500">*</span><div class="relative mt-1.5"><input v-model="form.auth_token" :type="showToken ? 'text' : 'password'" :placeholder="deployment?.has_token ? t('websites.git.connect.tokenSaved') : t('websites.git.pastePat')" autocomplete="new-password" class="w-full rounded-lg border-slate-300 pr-20 dark:bg-slate-800" /><button type="button" @click="showToken = !showToken" class="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs text-indigo-600">{{ showToken ? t('websites.git.hide') : t('websites.git.show') }}</button></div><span class="mt-1 block text-xs font-normal text-slate-500">{{ t('websites.git.connect.tokenHelp') }}</span></label>
                    </template>
                </div>
                <div class="mt-6 flex flex-wrap items-center gap-3"><button v-if="!cloned || repoChanged" @click="connectAndDeploy" :disabled="!canSave || !canFirstAction" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40"><span v-if="busy" class="mr-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-white border-r-transparent"></span><i v-else class="bi bi-plug-fill mr-2"></i>{{ busy ? progressText : firstAction === 'init' ? t('websites.git.connect.connectPush') : t('websites.git.connect.connectDeploy') }}</button><button v-else @click="save" :disabled="!canSave" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-40">{{ busy === 'save' ? t('websites.git.savingShort') : t('websites.git.connect.update') }}</button><button v-if="!deployment && !cloned" @click="save" :disabled="!canSave" class="rounded-lg border border-indigo-300 px-4 py-2.5 text-sm font-medium text-indigo-700 disabled:opacity-40">{{ t('websites.git.connect.saveOnly') }}</button><span v-if="!canSave && !busy" class="text-xs text-slate-500">{{ t('websites.git.connect.required') }}</span><span v-if="deployment && !repoChanged" class="text-sm text-emerald-600"><i class="bi bi-shield-check mr-1"></i>{{ t('websites.git.connect.savedSecurely') }}</span><button v-if="deployment && !confirmingDisconnect" type="button" @click="confirmingDisconnect = true" :disabled="isBusy" class="ml-auto rounded-lg border border-red-300 px-4 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-40 dark:hover:bg-red-950/30"><i class="bi bi-x-circle mr-2"></i>{{ busy === 'disconnect' ? t('websites.git.disconnecting') : t('websites.git.disconnect') }}</button></div>
                <div v-if="deployment && confirmingDisconnect" class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200"><p class="min-w-0 flex-1"><i class="bi bi-exclamation-triangle-fill mr-1"></i><i18n-t keypath="websites.git.disconnectConfirm" scope="global"><template #repo><strong class="break-all">{{ deployment.repository_url }}</strong></template></i18n-t></p><button type="button" @click="disconnect" :disabled="isBusy" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-40">{{ t('websites.git.disconnectYes') }}</button><button type="button" @click="confirmingDisconnect = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-slate-600">{{ t('common.cancel') }}</button></div>
                <div v-if="probing" class="mt-4 flex items-center gap-2 text-sm text-slate-500"><span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-slate-400 border-r-transparent"></span>{{ t('websites.git.probing') }}</div>
                <div v-else-if="probeError" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300"><i class="bi bi-exclamation-triangle mr-1"></i>{{ t('websites.git.probeFailed', { error: probeError }) }}</div>
                <div v-else-if="decision" class="mt-4 flex gap-3 rounded-lg border p-3 text-sm" :class="decisionClass(decision.tone)"><i class="bi mt-0.5" :class="decision.icon"></i><div><p class="font-semibold">{{ decision.title }}</p><p class="mt-0.5">{{ decision.text }}</p></div></div>
                <p v-if="repoChanged && cloned" class="mt-3 text-xs text-amber-700"><i class="bi bi-info-circle mr-1"></i>{{ t('websites.git.repoChanged') }}</p>
            </section>

            <section v-show="activePanel === 'deploy'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-violet-100 font-semibold text-violet-700">2</span><div><h2 class="font-semibold">{{ t('websites.git.deploy.title') }}</h2><p class="mt-1 text-sm text-slate-500">{{ actionHelp }}</p></div></div>
                <div v-if="busy && busy !== 'save'" class="mt-5 rounded-lg bg-indigo-50 p-4 text-sm text-indigo-700"><span class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-indigo-600 border-r-transparent"></span>{{ t('websites.git.deploy.running', { action: operationLabel(busy) }) }}</div>
                <div class="mt-5 flex flex-wrap gap-2">
                    <button v-if="!cloned && firstAction !== 'init' && firstAction !== 'none'" @click="run('clone')" :disabled="busy || !deployment || (firstAction === 'clone' && !probeState?.branch_exists)" :title="!deployment ? t('websites.git.deploy.saveFirst') : ''" class="rounded-lg bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-40"><i class="bi bi-rocket-takeoff mr-2"></i>{{ busy === 'clone' ? t('websites.git.deploy.deploying') : t('websites.git.op.clone') }}</button>
                    <button v-if="!cloned && firstAction !== 'clone' && firstAction !== 'none'" @click="run('init')" :disabled="busy || !deployment || probeState?.local_repo" :title="!deployment ? t('websites.git.deploy.saveFirst') : t('websites.git.deploy.initHint')" class="rounded-lg border border-violet-300 px-5 py-2.5 text-sm font-semibold text-violet-700 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-violet-700 dark:text-violet-300 dark:hover:bg-violet-950/30"><i class="bi bi-cloud-upload mr-2"></i>{{ busy === 'init' ? t('websites.git.pushing') : t('websites.git.op.init') }}</button>
                    <template v-else><button v-for="action in ['status','pull','push','sync']" :key="action" @click="run(action)" :disabled="isBusy" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-600 dark:text-slate-100 dark:hover:bg-slate-800"><i class="bi mr-2" :class="{ 'bi-activity': action === 'status', 'bi-cloud-download': action === 'pull', 'bi-cloud-upload': action === 'push', 'bi-arrow-repeat': action === 'sync' }"></i>{{ operationLabel(action) }}</button><button @click="reclone" :disabled="busy || repoChanged" :title="repoChanged ? t('websites.git.deploy.recloneBlocked') : ''" class="rounded-lg border border-amber-300 px-4 py-2.5 text-sm font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-40 dark:hover:bg-amber-950/30"><i class="bi bi-arrow-counterclockwise mr-2"></i>{{ busy === 'clone' ? t('websites.git.deploy.recloning') : t('websites.git.deploy.reclone') }}</button></template>
                </div>
                <div v-if="!deployment" class="mt-3 text-xs text-amber-700"><i class="bi bi-lock mr-1"></i>{{ t('websites.git.deploy.locked') }}</div>
                <div v-if="cloned && !repoChanged" class="mt-5 rounded-lg border p-4" :class="needsResolution || conflictState ? 'border-amber-300 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/20' : 'dark:border-slate-700'">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium"><i class="bi bi-sign-merge-left mr-1"></i>{{ t('websites.git.conflicts.title') }}</p>
                            <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ needsResolution ? t('websites.git.conflicts.stopped') : t('websites.git.conflicts.help') }} {{ t('websites.git.conflicts.legend') }}</p>
                        </div>
                        <button v-if="!pendingPush" type="button" @click="checkConflicts" :disabled="isBusy" class="rounded-lg border border-amber-400 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100 disabled:opacity-40 dark:text-amber-200 dark:hover:bg-amber-900/30"><i class="bi bi-search mr-1"></i>{{ busy === 'conflicts' ? t('websites.git.checking') : t('websites.git.conflicts.check') }}</button>
                    </div>
                    <div v-if="pendingPush" class="mt-4 rounded-lg border border-emerald-300 bg-white p-4 dark:border-emerald-800 dark:bg-slate-900">
                        <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-300"><i class="bi bi-hourglass-split mr-1"></i>{{ t('websites.git.pending.title') }}</p>
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ t('websites.git.pending.help') }}</p>
                        <p v-if="pendingPush.summary" class="mt-3 text-xs font-medium">{{ pendingPush.summary }}</p>
                        <ul class="mt-2 max-h-48 space-y-0.5 overflow-auto font-mono text-[11px]">
                            <li v-for="file in pendingPush.files" :key="file.path" class="break-all"><span class="inline-block w-16 font-sans" :class="{ added: 'text-emerald-600', deleted: 'text-red-600', changed: 'text-amber-600' }[fileStatus(file.status)]">{{ fileStatusLabel(file.status) }}</span>{{ file.path }}</li>
                        </ul>
                        <details v-if="pendingPush.commits?.length" class="mt-2 text-xs"><summary class="cursor-pointer text-slate-500">{{ t('websites.git.pending.commits', { count: pendingPush.commits.length }) }}</summary><ul class="mt-1 font-mono text-[11px]"><li v-for="commit in pendingPush.commits" :key="commit">{{ commit }}</li></ul></details>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" @click="finishResolution('confirm')" :disabled="isBusy" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"><i class="bi bi-cloud-upload mr-1"></i>{{ busy === 'confirm_push' ? t('websites.git.pushing') : t('websites.git.pending.confirm') }}</button>
                            <button type="button" @click="finishResolution('undo')" :disabled="isBusy" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-600 disabled:opacity-40"><i class="bi bi-arrow-counterclockwise mr-1"></i>{{ busy === 'undo_resolve' ? t('websites.git.pending.undoing') : t('websites.git.pending.undo') }}</button>
                        </div>
                    </div>
                    <template v-if="conflictState && !pendingPush">
                        <p class="mt-3 text-sm" :class="conflictState.conflicts.length ? 'text-amber-800 dark:text-amber-200' : 'text-emerald-700 dark:text-emerald-300'">
                            {{ conflictState.conflicts.length ? t('websites.git.conflicts.count', { count: conflictState.conflicts.length }) : (conflictState.behind ? t('websites.git.conflicts.none') : t('websites.git.conflicts.nothing')) }}
                        </p>
                        <div v-if="conflictState.behind" class="mt-3 flex flex-wrap gap-2">
                            <button type="button" @click="resolveConflicts('live')" :disabled="isBusy" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40 dark:bg-slate-200 dark:text-slate-900"><i class="bi bi-hdd-network mr-1"></i>{{ t('websites.git.conflicts.keepLive') }}</button>
                            <button type="button" @click="resolveConflicts('remote')" :disabled="isBusy" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"><i class="bi bi-github mr-1"></i>{{ t('websites.git.conflicts.keepLocal') }}</button>
                            <button type="button" @click="resolveConflicts('both')" :disabled="isBusy" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 disabled:opacity-40 dark:text-indigo-300"><i class="bi bi-intersect mr-1"></i>{{ t('websites.git.conflicts.keepBoth') }}</button>
                            <button v-if="aiAvailable && conflictState.conflicts.length" type="button" @click="askAi" :disabled="isBusy" class="rounded-lg border border-violet-300 px-4 py-2 text-sm font-semibold text-violet-700 disabled:opacity-40 dark:text-violet-300"><i class="bi bi-stars mr-1"></i>{{ busy === 'ai' ? t('websites.git.conflicts.aiMerging') : t('websites.git.conflicts.ai') }}</button>
                            <span v-else-if="conflictState.conflicts.length" class="self-center text-xs text-slate-500">{{ t('websites.git.conflicts.aiHint') }}</span>
                        </div>
                        <div v-for="file in conflictState.conflicts" :key="file.path" class="mt-4 rounded-lg border bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="min-w-0 break-all font-mono text-xs font-semibold">{{ file.path }} <span v-if="file.kind !== 'text'" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 font-sans font-normal text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ file.kind.replace('_', ' ') }}</span></p>
                                <div class="flex flex-wrap gap-1 text-xs">
                                    <label v-for="side in sides" :key="side.id" class="cursor-pointer rounded-md border px-2 py-1" :class="choices[file.path] === side.id ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300' : 'dark:border-slate-700'"><input v-model="choices[file.path]" type="radio" :value="side.id" class="sr-only" />{{ side.label }} <span class="text-slate-400">· {{ side.hint }}</span></label>
                                    <label v-if="aiSuggestions[file.path] !== undefined" class="cursor-pointer rounded-md border px-2 py-1" :class="choices[file.path] === 'ai' ? 'border-violet-500 bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300' : 'dark:border-slate-700'"><input v-model="choices[file.path]" type="radio" value="ai" class="sr-only" /><i class="bi bi-stars"></i> AI</label>
                                </div>
                            </div>
                            <p v-if="aiSkipped[file.path]" class="mt-2 text-xs text-amber-700 dark:text-amber-300">{{ aiSkipped[file.path] }}</p>
                            <div v-if="file.kind === 'text' || file.kind === 'deleted'" class="mt-2 flex flex-wrap gap-1 text-[11px]">
                                <button v-for="tab in ['merged', 'live', 'remote', 'base', ...(aiSuggestions[file.path] !== undefined ? ['ai'] : [])].filter((name) => name !== 'merged' || file.merged)" :key="tab" type="button" @click="viewing[file.path] = tab" class="rounded px-2 py-0.5" :class="viewing[file.path] === tab ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'">{{ t(`websites.git.view.${tab}`) }}</button>
                            </div>
                            <pre class="mt-2 max-h-72 overflow-auto rounded bg-slate-950 p-3 text-[11px] leading-relaxed text-slate-100">{{ versionText(file) }}</pre>
                        </div>
                        <button v-if="conflictState.conflicts.length" type="button" @click="resolveConflicts('custom')" :disabled="isBusy" class="mt-4 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-40"><i class="bi bi-check2-all mr-1"></i>{{ busy === 'resolve' ? t('websites.git.conflicts.merging') : t('websites.git.conflicts.apply') }}</button>
                    </template>
                </div>
                <div v-if="cloned && !repoChanged" class="mt-5 rounded-lg border p-4 dark:border-slate-700">
                    <p class="text-sm font-medium"><i class="bi bi-diagram-2 mr-1"></i>{{ t('websites.git.op.checkout') }}</p>
                    <p class="mt-1 text-xs text-slate-500"><i18n-t keypath="websites.git.switch.help" scope="global"><template #branch><code>{{ deployment?.branch }}</code></template></i18n-t></p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <select v-if="branches.length" v-model="targetBranch" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm sm:max-w-xs dark:bg-slate-800"><option v-for="branch in branches" :key="branch" :value="branch">{{ branch }}</option></select>
                        <input v-else v-model.trim="targetBranch" :placeholder="t('websites.git.switch.placeholder')" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm sm:max-w-xs dark:bg-slate-800" />
                        <button type="button" @click="switchBranch" :disabled="busy || !targetBranch || targetBranch === deployment?.branch" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-medium text-indigo-700 disabled:opacity-40"><i class="bi bi-shuffle mr-1"></i>{{ busy === 'checkout' ? t('websites.git.switch.switching') : t('websites.git.switch.checkout') }}</button>
                    </div>
                </div>
                <div class="mt-5 grid gap-3 rounded-lg bg-slate-50 p-4 text-xs text-slate-600 sm:grid-cols-3 dark:bg-slate-800/60 dark:text-slate-300"><p><i class="bi bi-check-circle mr-1 text-emerald-500"></i>{{ t('websites.git.safety.noDelete') }}</p><p><i class="bi bi-check-circle mr-1 text-emerald-500"></i>{{ t('websites.git.safety.noAutoMerge') }}</p><p><i class="bi bi-check-circle mr-1 text-emerald-500"></i>{{ t('websites.git.safety.lock') }}</p></div>
            </section>

            <section v-show="activePanel === 'automation'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 font-semibold text-emerald-700">3</span><div><h2 class="font-semibold">{{ t('websites.git.auto.title') }}</h2><p class="mt-1 text-sm text-slate-500">{{ t('websites.git.auto.help') }}</p></div></div>
                <div class="mt-5 grid gap-4 md:grid-cols-2"><label class="text-sm font-medium">{{ t('websites.git.auto.what') }}<select v-model="form.auto_action" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800"><option value="off">{{ t('websites.git.auto.off') }}</option><option value="pull">{{ t('websites.git.auto.pull') }}</option><option value="push">{{ t('websites.git.auto.push') }}</option><option value="sync">{{ t('websites.git.auto.sync') }}</option></select></label><label class="text-sm font-medium">{{ t('websites.git.auto.every') }}<select v-model.number="form.interval_minutes" :disabled="form.auto_action === 'off'" class="mt-1.5 w-full rounded-lg border-slate-300 disabled:opacity-50 dark:bg-slate-800"><option :value="5">{{ t('websites.git.auto.minutes', { n: 5 }) }}</option><option :value="15">{{ t('websites.git.auto.minutes', { n: 15 }) }}</option><option :value="30">{{ t('websites.git.auto.minutes', { n: 30 }) }}</option><option :value="60">{{ t('websites.git.auto.hour') }}</option><option :value="360">{{ t('websites.git.auto.hours', { n: 6 }) }}</option><option :value="1440">{{ t('websites.git.auto.day') }}</option></select></label></div>
                <button v-if="deployment" @click="save" :disabled="busy || repoChanged" class="mt-5 rounded-lg border border-indigo-300 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-40">{{ t('websites.git.auto.save') }}</button>
                <p class="mt-3 text-xs text-slate-500">{{ t('websites.git.auto.next') }} <strong>{{ date(deployment?.next_sync_at) }}</strong></p>

                <div v-if="deployment" class="mt-6 border-t pt-5 dark:border-slate-700">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div><h3 class="font-semibold"><i class="bi bi-lightning-charge mr-1 text-amber-500"></i>{{ t('websites.git.webhook.title') }}</h3><p class="mt-1 text-sm text-slate-500"><i18n-t keypath="websites.git.webhook.help" scope="global"><template #branch><code>{{ deployment.branch }}</code></template></i18n-t></p></div>
                        <button v-if="!deployment.deploy_on_push" type="button" @click="togglePushDeploy(true)" :disabled="isBusy" class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">{{ busy === 'webhook' ? t('websites.git.webhook.enabling') : t('websites.git.webhook.enable') }}</button>
                        <div v-else class="flex gap-2"><button type="button" @click="togglePushDeploy(true, true)" :disabled="isBusy" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700" :title="t('websites.git.webhook.rotateTitle')"><i class="bi bi-arrow-repeat mr-1"></i>{{ t('websites.git.webhook.rotate') }}</button><button type="button" @click="togglePushDeploy(false)" :disabled="isBusy" class="rounded-lg border border-red-300 px-3 py-2 text-sm text-red-600">{{ t('websites.git.webhook.disable') }}</button></div>
                    </div>
                    <div v-if="deployment.deploy_on_push" class="mt-4 space-y-2 rounded-lg bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
                        <p><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="deployment.github_hook_id ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">{{ deployment.github_hook_id ? t('websites.git.webhook.installed') : t('websites.git.webhook.manual') }}</span></p>
                        <p class="text-xs text-slate-500"><i18n-t keypath="websites.git.webhook.payload" scope="global"><template #type><code>application/json</code></template><template #event><em>push</em></template></i18n-t></p>
                        <code class="block break-all rounded bg-white px-2 py-1.5 text-xs dark:bg-slate-900">{{ deployment.webhook_url }}</code>
                        <template v-if="webhookSecret"><p class="text-xs text-slate-500">{{ t('websites.git.webhook.secretOnce') }}</p><code class="block break-all rounded bg-white px-2 py-1.5 text-xs dark:bg-slate-900">{{ webhookSecret }}</code></template>
                        <p v-else-if="!deployment.github_hook_id" class="text-xs text-amber-700">{{ t('websites.git.webhook.lost') }}</p>
                    </div>
                </div>
            </section>

            <section v-show="activePanel === 'yaml'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700"><i class="bi bi-filetype-yml"></i></span><div><h2 class="font-semibold">{{ t('websites.git.yaml.title') }}</h2><p class="mt-1 text-sm text-slate-500"><i18n-t keypath="websites.git.yaml.help" scope="global"><template #domain><strong>{{ website.domain }}</strong></template></i18n-t></p></div></div>
                    <button type="button" @click="copyYaml" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white dark:bg-indigo-600"><i class="bi mr-2" :class="yamlCopied ? 'bi-check-lg' : 'bi-copy'"></i>{{ yamlCopied ? t('websites.git.yaml.copied') : t('websites.git.yaml.copy') }}</button>
                </div>

                <ol class="mt-5 grid gap-3 text-sm md:grid-cols-3">
                    <li class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><strong>{{ t('websites.git.yaml.step1') }}</strong><p class="mt-1 text-xs text-slate-500"><i18n-t keypath="websites.git.yaml.step1Help" scope="global"><template #file><code>.github/workflows/deploy.yml</code></template></i18n-t></p></li>
                    <li class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><strong>{{ t('websites.git.yaml.step2') }}</strong><p class="mt-1 text-xs text-slate-500">{{ t('websites.git.yaml.step2Help') }}</p></li>
                    <li class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><strong>{{ t('websites.git.yaml.step3') }}</strong><p class="mt-1 text-xs text-slate-500"><i18n-t keypath="websites.git.yaml.step3Help" scope="global"><template #branch><code>{{ form.branch || 'main' }}</code></template></i18n-t></p></li>
                </ol>

                <div class="mt-5 overflow-hidden rounded-lg border border-slate-700 bg-slate-950">
                    <div class="flex items-center justify-between border-b border-slate-700 px-4 py-2 text-xs text-slate-400"><span>.github/workflows/deploy.yml</span><span>{{ website.root_path }}</span></div>
                    <pre class="max-h-[34rem] overflow-auto p-4 text-xs leading-5 text-slate-200"><code>{{ githubActionsYaml }}</code></pre>
                </div>

                <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    <p class="font-semibold"><i class="bi bi-key mr-2"></i>{{ t('websites.git.yaml.secrets') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2"><code v-for="name in ['SSH_HOST', 'SSH_USER', 'SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS']" :key="name" class="rounded bg-white/70 px-2 py-1 dark:bg-slate-900">{{ name }}</code><code class="rounded bg-white/70 px-2 py-1 dark:bg-slate-900">SSH_PORT ({{ t('websites.git.yaml.optional') }})</code></div>
                    <p class="mt-3 text-xs"><i18n-t keypath="websites.git.yaml.note" scope="global"><template #path><code>{{ website.root_path }}</code></template></i18n-t></p>
                </div>
            </section>

            <section v-show="activePanel === 'activity'" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-4"><div><h2 class="font-semibold">{{ t('websites.git.activity.title') }}</h2><p class="text-xs text-slate-500">{{ t('websites.git.activity.help') }}</p></div><button v-if="deployment" @click="run('status')" :disabled="isBusy" class="rounded-lg border px-3 py-2 text-xs dark:border-slate-700"><i class="bi bi-arrow-clockwise mr-1"></i>{{ t('websites.git.activity.refresh') }}</button></div>
                <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">{{ t('websites.git.activity.time') }}</th><th>{{ t('websites.git.activity.operation') }}</th><th>{{ t('websites.git.activity.result') }}</th><th>{{ t('websites.git.activity.details') }}</th></tr></thead><tbody><tr v-for="log in logs" :key="log.id" class="border-t align-top dark:border-slate-800"><td class="py-3 pr-4 whitespace-nowrap">{{ date(log.created_at) }}</td><td class="pr-4">{{ operationLabel(log.action) }}</td><td class="pr-4"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="log.status === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">{{ log.status === 'success' ? t('websites.git.activity.success') : t('websites.git.activity.attention') }}</span></td><td class="max-w-xl whitespace-pre-wrap break-words text-xs text-slate-600 dark:text-slate-300">{{ log.message }}</td></tr><tr v-if="!logs.length"><td colspan="4" class="py-10 text-center"><i class="bi bi-clock-history block text-2xl text-slate-300"></i><p class="mt-2 text-slate-500">{{ t('websites.git.activity.empty') }}</p><p class="text-xs text-slate-400">{{ t('websites.git.activity.emptyHelp') }}</p></td></tr></tbody></table></div>
            </section>
            </template>
            </main>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
