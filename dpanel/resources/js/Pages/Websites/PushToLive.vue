<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: { type: Object, required: true },
    tokens: { type: Array, default: () => [] },
    expiryOptions: { type: Array, default: () => [1, 24, 168, 720] },
    panelHostUrl: { type: String, default: '' },
});

const { panelRoute, requestJson } = usePanelApi();

// The site being pushed from, shown up top so it's clear what gets sent.
const sourceUrl = computed(() => (props.website.domain ? `${props.website.enable_ssl ? 'https' : 'http'}://${props.website.domain}` : ''));
const sourceDirectory = computed(() => String(props.website.root_path || props.website.project_root || ''));

// ---- Receive side (this server is the live one) ----
const tokens = ref([...props.tokens]);
const tokenName = ref('');
const tokenExpiry = ref(24);
const creatingToken = ref(false);
// The plaintext token, shown once right after generating it.
const newToken = ref(null);
const copied = ref('');
const receiveError = ref('');

const expiryLabel = (hours) => ({ 1: '1 hour', 24: '24 hours', 168: '7 days', 720: '30 days' }[hours] || `${hours} hours`);

const createToken = async () => {
    creatingToken.value = true;
    receiveError.value = '';
    copied.value = '';
    try {
        const data = await requestJson(panelRoute('websites.push-to-live.tokens.store', { id: props.website.id }), {
            body: { name: tokenName.value, expires_in_hours: tokenExpiry.value },
        });
        newToken.value = { token: data.push_token };
        tokens.value = data.tokens || tokens.value;
        tokenName.value = '';
    } catch (error) {
        receiveError.value = error.message || 'Push token could not be created.';
    } finally {
        creatingToken.value = false;
    }
};

const copyText = async (what, value) => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = what;
    } catch {
        copied.value = '';
    }
};

// The plaintext isn't stored, so a token that wasn't copied can only be replaced.
const regenerateToken = async (token) => {
    if (!confirm(`Regenerate "${token.name}"? The current token stops working immediately — any panel using it needs the new one.`)) return;
    receiveError.value = '';
    copied.value = '';
    try {
        const data = await requestJson(panelRoute('websites.push-to-live.tokens.regenerate', { id: props.website.id, tokenId: token.id }));
        newToken.value = { token: data.push_token, name: token.name };
        tokens.value = data.tokens || tokens.value;
    } catch (error) {
        receiveError.value = error.message || 'Token could not be regenerated.';
    }
};

const revokeToken = async (token) => {
    if (!confirm(`Revoke "${token.name}"? A push using it will stop working immediately.`)) return;
    try {
        const data = await requestJson(panelRoute('websites.push-to-live.tokens.destroy', { id: props.website.id, tokenId: token.id }), { method: 'DELETE' });
        tokens.value = data.tokens || [];
    } catch (error) {
        receiveError.value = error.message || 'Token could not be revoked.';
    }
};

// ---- Push side (this server is the local one) ----
// site: files + database replace the live site; files: only the files that
// differ are sent (.gitignore applies, .git never goes, the database stays).
const mode = ref('site');
const deleteMissing = ref(false);
// Mirrors the stages PushWebsiteToLiveJob reports, so a failure names its step.
const stepsFor = (pushMode) => [
    { key: 'connecting', label: 'Connect' },
    ...(pushMode === 'files' ? [{ key: 'comparing', label: 'Compare' }] : []),
    { key: 'archiving', label: 'Package' },
    { key: 'uploading', label: 'Upload' },
    { key: 'finishing', label: 'Verify' },
    { key: 'remote', label: pushMode === 'files' ? 'Live apply' : 'Live restore' },
    { key: 'completed', label: 'Done' },
];
const STORAGE_KEY = `dpanel.pushToLive.${props.website.id}`;
const hostUrl = ref('');
const pushToken = ref('');
const canConnect = computed(() => hostUrl.value.trim() !== '' && pushToken.value.trim() !== '');
// Set by "Connect": what the token would overwrite on the live panel. Push
// stays locked until the admin types that domain back, since pushing to the
// wrong site replaces it.
const target = ref(null);
const confirmDomain = ref('');
const connecting = ref(false);
const connectError = ref('');
const domainConfirmed = computed(() => Boolean(target.value?.live_domain) && confirmDomain.value.trim().toLowerCase() === target.value.live_domain.toLowerCase());
const push = ref(null);
const lastStepIndex = ref(0);
const pushSteps = computed(() => stepsFor(push.value?.mode || mode.value));
const starting = ref(false);
let pollTimer = null;

const stepIndexFor = (stage) => {
    if (!stage) return -1;
    if (stage.startsWith('remote_')) return pushSteps.value.findIndex((step) => step.key === 'remote');

    return pushSteps.value.findIndex((step) => step.key === stage);
};
const currentStepIndex = computed(() => {
    // A failure names the step it happened in; polls may have missed that step.
    const index = stepIndexFor(push.value?.stage === 'failed' ? push.value?.failed_stage : push.value?.stage);

    return index >= 0 ? index : lastStepIndex.value;
});
const pushRunning = computed(() => push.value && !['completed', 'failed'].includes(push.value.stage));
const stepState = (index) => {
    if (push.value?.stage === 'completed' || index < currentStepIndex.value) return 'done';
    if (index === currentStepIndex.value) return push.value?.stage === 'failed' ? 'failed' : 'current';

    return 'pending';
};

const remember = (value) => {
    try {
        if (value) window.localStorage.setItem(STORAGE_KEY, value);
        else window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        // storage unavailable — progress still shows for this page view
    }
};

const stopPolling = () => {
    if (pollTimer) window.clearInterval(pollTimer);
    pollTimer = null;
};

const poll = async () => {
    const id = push.value?.id;
    if (!id) return;
    try {
        const data = await requestJson(panelRoute('websites.push-to-live.status', { id: props.website.id, pushId: id }), { method: 'GET' });
        const index = stepIndexFor(data.stage);
        if (index >= 0) lastStepIndex.value = index;
        push.value = { ...push.value, ...data, id };
    } catch (error) {
        push.value = { ...push.value, stage: 'failed', message: error.message || 'Push status could not be read.' };
    }
    if (!pushRunning.value) {
        stopPolling();
        remember(null);
    }
};

const startPolling = () => {
    stopPolling();
    poll();
    pollTimer = window.setInterval(poll, 3000);
};

const resetTarget = () => {
    target.value = null;
    confirmDomain.value = '';
    connectError.value = '';
};

const connect = async () => {
    if (!canConnect.value) return;
    connecting.value = true;
    resetTarget();
    try {
        const data = await requestJson(panelRoute('websites.push-to-live.connect', { id: props.website.id }), {
            body: { host_url: hostUrl.value.trim(), push_token: pushToken.value.trim() },
        });
        if (!data.ok || !data.live_domain) {
            throw new Error(data.message || 'The live panel did not say which website this token is for.');
        }
        target.value = data;
        hostUrl.value = data.host_url;
    } catch (error) {
        connectError.value = error.message || 'Could not connect to the live panel.';
    } finally {
        connecting.value = false;
    }
};

const startPush = async () => {
    if (!domainConfirmed.value) return;
    starting.value = true;
    try {
        const data = await requestJson(panelRoute('websites.push-to-live.push', { id: props.website.id }), {
            body: {
                host_url: target.value.host_url,
                push_token: pushToken.value.trim(),
                confirm_domain: target.value.live_domain,
                mode: mode.value,
                delete_missing: mode.value === 'files' && deleteMissing.value,
            },
        });
        push.value = { id: data.push_id, stage: 'queued', percent: 0, message: 'Waiting for the backup worker', live_domain: data.live_domain, mode: data.mode };
        lastStepIndex.value = 0;
        remember(data.push_id);
        pushToken.value = '';
        resetTarget();
        startPolling();
    } catch (error) {
        push.value = { stage: 'failed', percent: 0, message: error.message || 'Push could not be started.' };
    } finally {
        starting.value = false;
    }
};

onMounted(() => {
    try {
        const saved = window.localStorage.getItem(STORAGE_KEY);
        if (saved) {
            push.value = { id: saved, stage: 'queued', percent: 0, message: 'Reconnecting…' };
            startPolling();
        }
    } catch {
        // ignore unreadable saved state
    }
});
onUnmounted(stopPolling);
</script>

<template>
    <Head :title="`Push to Live - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Push to Live</h1>
                    <p class="text-sm text-slate-500">{{ website.domain }} — push a site from a panel the internet can't reach (e.g. local) to a live panel</p>
                </div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700">
                    <i class="bi bi-arrow-left mr-1"></i> Website dashboard
                </Link>
            </div>
        </template>

        <section class="mb-6 rounded-xl border bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pushing from this site</p>
            <dl class="mt-2 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
                <dt class="text-slate-500"><i class="bi bi-globe2 mr-1"></i>URL</dt>
                <dd class="min-w-0 break-all">
                    <a v-if="sourceUrl" :href="sourceUrl" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ sourceUrl }}</a>
                    <span v-else>-</span>
                </dd>
                <dt class="text-slate-500"><i class="bi bi-folder2 mr-1"></i>Directory</dt>
                <dd class="flex min-w-0 items-center gap-2">
                    <span class="break-all font-mono text-xs">{{ sourceDirectory || '-' }}</span>
                    <button v-if="sourceDirectory" type="button" class="shrink-0 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" title="Copy directory" aria-label="Copy directory" @click="copyText('dir', sourceDirectory)">
                        <i :class="copied === 'dir' ? 'bi bi-check2' : 'bi bi-clipboard'"></i>
                    </button>
                </dd>
            </dl>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><i class="bi bi-cloud-upload"></i></span>
                    <div>
                        <h2 class="font-semibold">Push this website to a live server</h2>
                        <p class="mt-1 text-xs text-slate-500">On the live panel, open the target website's Push to Live page and generate a push token. Enter that panel's host URL and the token here — this panel connects out to it, so it doesn't need a public address itself. The live panel can be another server or this same one.</p>
                    </div>
                </div>

                <label class="mt-4 block text-xs font-medium text-slate-600 dark:text-slate-300" for="push-host">Live panel host URL</label>
                <input id="push-host" v-model="hostUrl" :disabled="pushRunning || starting || connecting" type="text" placeholder="https://panel.example.com:2083" autocomplete="url" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950" @input="resetTarget" />
                <label class="mt-3 block text-xs font-medium text-slate-600 dark:text-slate-300" for="push-token">Push token</label>
                <input id="push-token" v-model="pushToken" :disabled="pushRunning || starting || connecting" type="password" placeholder="dp-push-…" autocomplete="off" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-xs dark:border-slate-700 dark:bg-slate-950" @input="resetTarget" />
                <p class="mt-3 text-xs font-medium text-slate-600 dark:text-slate-300">What to push</p>
                <div class="mt-1 grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="What to push">
                    <label v-for="option in [
                        { value: 'site', icon: 'bi-box-seam', title: 'Whole site', text: 'Files and database replace the live site.' },
                        { value: 'files', icon: 'bi-arrow-left-right', title: 'Files only (sync)', text: 'Only changed files. Follows .gitignore, skips .git, keeps the database.' },
                    ]" :key="option.value" class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-xs" :class="mode === option.value ? 'border-violet-500 bg-violet-50 dark:border-violet-500 dark:bg-violet-950' : 'border-slate-200 dark:border-slate-700'">
                        <input v-model="mode" type="radio" :value="option.value" :disabled="pushRunning || starting" class="mt-0.5 text-violet-600" />
                        <span>
                            <span class="block font-semibold text-slate-800 dark:text-slate-100"><i :class="['bi', option.icon, 'mr-1']"></i>{{ option.title }}</span>
                            <span class="mt-0.5 block text-slate-500">{{ option.text }}</span>
                        </span>
                    </label>
                </div>
                <label v-if="mode === 'files'" class="mt-2 flex items-start gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input v-model="deleteMissing" type="checkbox" :disabled="pushRunning || starting" class="mt-0.5 rounded text-violet-600" />
                    <span>Also remove files on the live site that were deleted here <span class="text-slate-400">(files .gitignore excludes on the live site, such as .env or uploads, are never removed)</span></span>
                </label>
                <p v-if="connectError" class="mt-2 break-words text-xs text-red-600">{{ connectError }}</p>
                <div v-if="!target" class="mt-3 flex justify-end">
                    <button type="button" :disabled="!canConnect || pushRunning || connecting" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50" @click="connect">
                        <i class="bi bi-plug mr-1"></i>{{ connecting ? 'Connecting…' : 'Connect' }}
                    </button>
                </div>

                <div v-if="target" class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300"><i class="bi bi-exclamation-triangle mr-1"></i>Confirm the target</p>
                    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                        <dt class="text-slate-500">From</dt>
                        <dd class="break-all"><span class="font-medium">{{ sourceUrl || target.source_domain }}</span> <span class="text-xs text-slate-500">(this panel)</span><span v-if="sourceDirectory" class="block font-mono text-xs text-slate-500">{{ sourceDirectory }}</span></dd>
                        <dt class="text-slate-500">Will overwrite</dt>
                        <dd class="break-all text-base font-bold text-red-700 dark:text-red-400">{{ target.live_domain }}</dd>
                        <dt class="text-slate-500">Live panel</dt>
                        <dd class="break-all">{{ target.host_url }}<span v-if="target.server" class="text-xs text-slate-500"> · server {{ target.server }}</span></dd>
                        <dt v-if="target.token_name" class="text-slate-500">Token</dt>
                        <dd v-if="target.token_name" class="break-all">{{ target.token_name }}</dd>
                    </dl>
                    <p v-if="mode === 'files'" class="mt-3 text-xs text-amber-800 dark:text-amber-200">Files that differ on {{ target.live_domain }} will be replaced with this site's<template v-if="deleteMissing">, and files deleted here will be removed there</template>. Files .gitignore excludes, .git and the database are left alone. Every replaced or removed file is moved to its .trash first.</p>
                    <p v-else class="mt-3 text-xs text-amber-800 dark:text-amber-200">{{ target.live_domain }}'s files and database will be replaced with this site's. Its current files are moved to its .trash first.</p>
                    <label class="mt-3 block text-xs font-medium text-slate-700 dark:text-slate-200" for="push-confirm">Type <span class="font-mono font-semibold">{{ target.live_domain }}</span> to confirm</label>
                    <input id="push-confirm" v-model="confirmDomain" :disabled="starting" type="text" autocomplete="off" spellcheck="false" class="mt-1 w-full rounded-lg border-amber-300 font-mono text-sm dark:border-amber-800 dark:bg-slate-950" @keydown.enter="startPush" />
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" :disabled="starting" class="rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-slate-600" @click="resetTarget">Cancel</button>
                        <button type="button" :disabled="!domainConfirmed || starting" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50" @click="startPush">
                            <i class="bi bi-rocket-takeoff mr-1"></i>{{ starting ? 'Starting…' : mode === 'files' ? `Sync files to ${target.live_domain}` : `Push to ${target.live_domain}` }}
                        </button>
                    </div>
                </div>

                <div v-if="push" class="mt-5 rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-semibold">
                            <template v-if="push.mode === 'files'">{{ push.stage === 'completed' ? 'Files synced' : push.stage === 'failed' ? 'File sync failed' : 'Syncing files' }}</template>
                            <template v-else>{{ push.stage === 'completed' ? 'Pushed' : push.stage === 'failed' ? 'Push failed' : 'Pushing' }}</template>
                            <span v-if="push.live_domain || push.live_url" class="font-normal text-slate-500">→ {{ push.live_domain || push.live_url }}</span>
                        </p>
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-semibold tabular-nums">{{ push.stage === 'completed' ? 100 : (push.percent || 0) }}%</span>
                            <button v-if="!pushRunning" type="button" class="text-slate-400 hover:text-slate-600" aria-label="Dismiss" @click="push = null"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div :class="push.stage === 'failed' ? 'bg-red-500' : (push.stage === 'completed' ? 'bg-emerald-500' : 'bg-violet-500')" class="h-full rounded-full transition-all duration-500" :style="{ width: `${push.stage === 'completed' ? 100 : Math.max(2, push.percent || 0)}%` }"></div>
                    </div>
                    <ol class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        <li v-for="(step, index) in pushSteps" :key="step.key" class="flex items-center gap-1" :class="{
                            'text-emerald-600 dark:text-emerald-400': stepState(index) === 'done',
                            'font-semibold text-violet-600 dark:text-violet-400': stepState(index) === 'current',
                            'font-semibold text-red-600 dark:text-red-400': stepState(index) === 'failed',
                            'text-slate-400': stepState(index) === 'pending',
                        }">
                            <i v-if="stepState(index) === 'done'" class="bi bi-check-circle-fill"></i>
                            <i v-else-if="stepState(index) === 'current'" class="bi bi-arrow-repeat animate-spin"></i>
                            <i v-else-if="stepState(index) === 'failed'" class="bi bi-x-circle-fill"></i>
                            <i v-else class="bi bi-circle"></i>
                            {{ step.label }}
                        </li>
                    </ol>
                    <p :class="push.stage === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'" class="mt-2 break-words text-xs">
                        <template v-if="push.stage === 'failed'">Failed at "{{ pushSteps[currentStepIndex]?.label }}": </template>{{ push.message }}
                    </p>
                    <p v-if="push.warning" class="mt-2 break-words rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                        <i class="bi bi-exclamation-triangle mr-1"></i>{{ push.warning }}
                    </p>
                    <p v-if="pushRunning" class="mt-2 text-[11px] text-slate-400">Runs in the background — you can leave this page; progress resumes when you come back.</p>
                </div>
            </section>

            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-cyan-500/10 text-cyan-600 dark:text-cyan-400"><i class="bi bi-key"></i></span>
                    <div>
                        <h2 class="font-semibold">Receive pushes on this website</h2>
                        <p class="mt-1 text-xs text-slate-500">Use this on the live panel. A push token lets another panel overwrite <span class="font-medium">{{ website.domain }}</span> — and only this website — until it expires or you revoke it.</p>
                    </div>
                </div>

                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-950">
                    <p class="text-[11px] font-medium text-slate-600 dark:text-slate-300">Host URL of this panel</p>
                    <div class="mt-1 flex items-center gap-2">
                        <p class="min-w-0 flex-1 break-all font-mono text-xs text-slate-800 dark:text-slate-100">{{ panelHostUrl }}</p>
                        <button type="button" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-white dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" @click="copyText('host', panelHostUrl)">
                            <i class="bi bi-clipboard mr-1"></i>{{ copied === 'host' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">The address this page is open on — enter it as "Live panel host URL" on the pushing panel. If that panel reaches this server by another domain or IP, use that instead.</p>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                    <input v-model="tokenName" type="text" maxlength="80" placeholder="Name (e.g. My laptop)" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950" />
                    <select v-model.number="tokenExpiry" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950" aria-label="Expires after">
                        <option v-for="hours in expiryOptions" :key="hours" :value="hours">{{ expiryLabel(hours) }}</option>
                    </select>
                    <button type="button" :disabled="creatingToken" class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-700 disabled:opacity-50" @click="createToken">
                        {{ creatingToken ? 'Generating…' : 'Generate push token' }}
                    </button>
                </div>
                <p v-if="receiveError" class="mt-2 text-xs text-red-600">{{ receiveError }}</p>

                <div v-if="newToken" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-800 dark:bg-emerald-950">
                    <p class="text-xs font-semibold text-emerald-800 dark:text-emerald-200"><template v-if="newToken.name">New token for "{{ newToken.name }}" — </template>Copy the push token now — it won't be shown again.</p>
                    <p class="mt-3 text-[11px] font-medium text-emerald-800 dark:text-emerald-200">Push token</p>
                    <div class="mt-1 flex items-start gap-2">
                        <p class="min-w-0 flex-1 break-all rounded bg-white p-2 font-mono text-[11px] text-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ newToken.token }}</p>
                        <button type="button" class="shrink-0 rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700 dark:text-emerald-300" @click="copyText('token', newToken.token)">
                            <i class="bi bi-clipboard mr-1"></i>{{ copied === 'token' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <p class="mt-2 text-[11px] text-emerald-700 dark:text-emerald-300">Use it with the host URL above.</p>
                    <div class="mt-2 flex justify-end">
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs dark:border-slate-600" @click="newToken = null">Done</button>
                    </div>
                </div>

                <h3 class="mt-6 text-xs font-semibold uppercase tracking-wide text-slate-500">Push tokens</h3>
                <ul class="mt-2 divide-y divide-slate-200 dark:divide-slate-800">
                    <li v-for="token in tokens" :key="token.id" class="flex flex-wrap items-start justify-between gap-2 py-3 text-xs">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-800 dark:text-slate-100">
                                {{ token.name }}
                                <span class="ml-1 font-mono text-[10px] text-slate-400">{{ token.prefix }}…</span>
                                <span v-if="!token.active" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-slate-800">expired</span>
                            </p>
                            <p class="mt-0.5 text-slate-500">Expires {{ token.expires_at || 'never' }} · Last used {{ token.last_used_at || 'never' }}<template v-if="token.last_used_ip"> from {{ token.last_used_ip }}</template></p>
                            <p v-if="token.last_push_status" class="mt-0.5 break-words" :class="token.last_push_status === 'failed' ? 'text-red-600' : token.last_push_status === 'completed' ? 'text-emerald-600' : 'text-slate-500'">
                                Last push: {{ token.last_push_status }}<template v-if="token.last_push_message"> — {{ token.last_push_message }}</template>
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button type="button" class="rounded border border-slate-300 px-2 py-1 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" title="Shows a new token; the current one stops working" @click="regenerateToken(token)"><i class="bi bi-arrow-repeat mr-1"></i>Regenerate</button>
                            <button type="button" class="rounded border border-red-300 px-2 py-1 text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400" @click="revokeToken(token)">Revoke</button>
                        </div>
                    </li>
                    <li v-if="tokens.length === 0" class="py-4 text-center text-xs text-slate-500">No push tokens yet.</li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
