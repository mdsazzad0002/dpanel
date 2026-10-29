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
// Mirrors the stages PushWebsiteToLiveJob reports, so a failure names its step.
const pushSteps = [
    { key: 'connecting', label: 'Connect' },
    { key: 'archiving', label: 'Package' },
    { key: 'uploading', label: 'Upload' },
    { key: 'finishing', label: 'Verify' },
    { key: 'remote', label: 'Live restore' },
    { key: 'completed', label: 'Done' },
];
const STORAGE_KEY = `dpanel.pushToLive.${props.website.id}`;
const hostUrl = ref('');
const pushToken = ref('');
const canPush = computed(() => hostUrl.value.trim() !== '' && pushToken.value.trim() !== '');
const push = ref(null);
const lastStepIndex = ref(0);
const starting = ref(false);
let pollTimer = null;

const stepIndexFor = (stage) => {
    if (!stage) return -1;
    if (stage.startsWith('remote_')) return pushSteps.findIndex((step) => step.key === 'remote');

    return pushSteps.findIndex((step) => step.key === stage);
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

const startPush = async () => {
    if (!canPush.value) return;
    if (!confirm('The live website will be overwritten with this site\'s files and database. Its current files are moved to its .trash first. Continue?')) return;
    starting.value = true;
    try {
        const data = await requestJson(panelRoute('websites.push-to-live.push', { id: props.website.id }), { body: { host_url: hostUrl.value.trim(), push_token: pushToken.value.trim() } });
        push.value = { id: data.push_id, stage: 'queued', percent: 0, message: 'Waiting for the backup worker', live_domain: data.live_domain };
        lastStepIndex.value = 0;
        remember(data.push_id);
        pushToken.value = '';
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
                <input id="push-host" v-model="hostUrl" :disabled="pushRunning || starting" type="text" placeholder="https://panel.example.com:2083" autocomplete="url" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950" />
                <label class="mt-3 block text-xs font-medium text-slate-600 dark:text-slate-300" for="push-token">Push token</label>
                <input id="push-token" v-model="pushToken" :disabled="pushRunning || starting" type="password" placeholder="dp-push-…" autocomplete="off" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-xs dark:border-slate-700 dark:bg-slate-950" />
                <div class="mt-3 flex justify-end">
                    <button type="button" :disabled="!canPush || pushRunning || starting" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50" @click="startPush">
                        <i class="bi bi-rocket-takeoff mr-1"></i>{{ starting ? 'Starting…' : 'Push to live' }}
                    </button>
                </div>

                <div v-if="push" class="mt-5 rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-semibold">
                            {{ push.stage === 'completed' ? 'Pushed' : push.stage === 'failed' ? 'Push failed' : 'Pushing' }}
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
                    <p class="text-xs font-semibold text-emerald-800 dark:text-emerald-200">Copy the push token now — it won't be shown again.</p>
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
                        <button type="button" class="rounded border border-red-300 px-2 py-1 text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400" @click="revokeToken(token)">Revoke</button>
                    </li>
                    <li v-if="tokens.length === 0" class="py-4 text-center text-xs text-slate-500">No push tokens yet.</li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
