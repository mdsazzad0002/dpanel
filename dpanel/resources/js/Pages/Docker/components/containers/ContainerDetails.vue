<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import LogViewer from '../LogViewer.vue';
import ContainerConsole from './ContainerConsole.vue';
import { useServerBaseDir } from '@/composables/useServerBaseDir';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const { isInsideBase } = useServerBaseDir();

// Everything about one container in one place. Opens on a tab ('overview',
// 'env', 'storage', 'network', 'console', 'logs') so "Logs" in the list jumps
// straight there.
const props = defineProps({
    container: { type: Object, default: null },
    tab: { type: String, default: 'overview' },
    networks: { type: Array, default: () => [] },
    busy: { type: String, default: '' },
    message: { type: Object, default: null },
    docker: { type: Object, required: true },
});

const emit = defineEmits(['close', 'action', 'edit', 'duplicate', 'rename', 'changed']);

const current = ref('overview');
const info = ref(null);
const loading = ref(false);
const error = ref('');
const revealed = ref(false);
const joinNetwork = ref('');
const joinAliases = ref('');
const netBusy = ref('');
const netError = ref('');

const tabs = [
    { key: 'overview', get label() { return t('docker.nav.sections.o'); }, icon: 'bi bi-info-circle' },
    { key: 'logs', get label() { return t('docker.shared.logs'); }, icon: 'bi bi-journal-text' },
    { key: 'console', get label() { return t('docker.shared.console'); }, icon: 'bi bi-terminal' },
    { key: 'env', get label() { return t('docker.console.quick.variables'); }, icon: 'bi bi-sliders' },
    { key: 'storage', get label() { return t('docker.overview.storage'); }, icon: 'bi bi-hdd' },
    { key: 'network', get label() { return t('docker.form.network'); }, icon: 'bi bi-diagram-3' },
];

const load = async () => {
    if (!props.container) return;
    loading.value = true;
    error.value = '';
    try {
        info.value = await props.docker.inspect(props.container.name || props.container.id);
    } catch (e) {
        error.value = props.docker.errorText(e);
    } finally {
        loading.value = false;
    }
};

watch(() => props.container, (c, old) => {
    if (!c) return;
    if (c.id !== old?.id) {
        info.value = null;
        revealed.value = false;
        current.value = props.tab;
    }
    load();
}, { immediate: true });
watch(() => props.tab, (value) => { current.value = value; });

const isSite = computed(() => String(props.container?.name || '').startsWith('dpanel-site-'));
const env = computed(() => (info.value?.env || []).map((pair) => {
    const at = pair.indexOf('=');
    return { key: pair.slice(0, at), value: pair.slice(at + 1) };
}));
const joinable = computed(() => {
    const joined = new Set((info.value?.networks || []).map((n) => n.name));
    return props.networks.filter((n) => !joined.has(n) && !['host', 'none'].includes(n));
});
const host = window.location.hostname;
const portUrl = (p) => (p.public ? `http://${host}:${p.host}` : null);

const fmtDate = (value) => {
    if (!value || value.startsWith('0001')) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
};

const copy = (text) => navigator.clipboard?.writeText(text).catch(() => {});

const network = async (action, name, aliases = []) => {
    netBusy.value = `${action}:${name}`;
    netError.value = '';
    try {
        await props.docker.post('docker.networks.action', { action, name, container: props.container.name, aliases });
        joinNetwork.value = '';
        joinAliases.value = '';
        await load();
        emit('changed');
    } catch (e) {
        netError.value = props.docker.errorText(e);
    } finally {
        netBusy.value = '';
    }
};

const fetchLogs = (lines) => props.docker.fetchLogs(props.container.name || props.container.id, lines);

const act = (action) => emit('action', action, props.container);
const btn = 'inline-flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-xs disabled:opacity-40';
const plain = `${btn} border-slate-300 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800`;
const stateClass = (state) => ({
    running: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    restarting: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    paused: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    exited: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
}[state] || 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300');
</script>

<template>
    <Modal :show="!!container" max-width="5xl" @close="$emit('close')">
        <div v-if="container" class="flex max-h-[92vh] flex-col">
            <div class="border-b border-slate-200 p-5 dark:border-slate-800">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="truncate text-base font-semibold">{{ container.name }}</h2>
                            <span :class="stateClass(container.state)" class="rounded px-1.5 py-0.5 text-[11px] font-semibold">{{ container.state }}</span>
                            <span v-if="info?.state?.health" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] dark:bg-slate-800">{{ t('docker.details.health', { value: info.state.health }) }}</span>
                            <span v-if="container.project" class="rounded bg-violet-100 px-1.5 py-0.5 text-[11px] text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">{{ t('docker.networkCard.stack', { name: container.project }) }}</span>
                        </div>
                        <p class="mt-0.5 truncate font-mono text-xs text-slate-500">{{ container.image }} · {{ container.id }}</p>
                    </div>
                    <button type="button" class="rounded-md px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" :aria-label="t('common.close')" @click="$emit('close')"><i class="bi bi-x-lg"></i></button>
                </div>

                <div v-if="isSite" class="mt-3 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
                    {{ t('docker.details.siteNote') }}
                </div>
                <div v-else-if="container.project" class="mt-3 rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-xs text-violet-800 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-200">
                    <i18n-t keypath="docker.details.stackNote" scope="global"><template #stack><strong>{{ container.project }}</strong></template></i18n-t>
                </div>

                <div class="mt-3 flex flex-wrap gap-1.5">
                    <button v-if="container.state === 'paused'" type="button" :disabled="busy === container.id" :class="plain" @click="act('unpause')"><i class="bi bi-play"></i>{{ t('docker.containerList.resume') }}</button>
                    <button v-else-if="container.state !== 'running'" type="button" :disabled="busy === container.id" :class="[btn, 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-950']" @click="act('start')"><i class="bi bi-play-fill"></i>{{ t('docker.shared.start') }}</button>
                    <template v-else>
                        <button type="button" :disabled="busy === container.id" :class="plain" @click="act('stop')"><i class="bi bi-stop-fill"></i>{{ t('docker.shared.stop') }}</button>
                        <button type="button" :disabled="busy === container.id" :class="plain" @click="act('pause')"><i class="bi bi-pause"></i>{{ t('docker.details.pause') }}</button>
                    </template>
                    <button type="button" :disabled="busy === container.id" :class="plain" @click="act('restart')"><i class="bi bi-arrow-repeat"></i>{{ t('docker.shared.restart') }}</button>
                    <button v-if="!isSite && !container.project" type="button" :disabled="!info" :class="plain" @click="$emit('edit', info.spec, container)"><i class="bi bi-pencil"></i>{{ t('docker.details.editRecreate') }}</button>
                    <button type="button" :disabled="!info" :class="plain" @click="$emit('duplicate', info.spec)"><i class="bi bi-copy"></i>{{ t('docker.details.duplicate') }}</button>
                    <button v-if="!isSite && !container.project" type="button" :disabled="busy === container.id" :class="plain" :title="t('docker.details.updateTitle')" @click="act('update')"><i class="bi bi-cloud-arrow-down"></i>{{ t('docker.details.updateImage') }}</button>
                    <button v-if="!isSite && !container.project" type="button" :class="plain" @click="$emit('rename', container)"><i class="bi bi-input-cursor-text"></i>{{ t('docker.details.rename') }}</button>
                    <button v-if="container.state === 'running'" type="button" :disabled="busy === container.id" :class="plain" @click="act('kill')"><i class="bi bi-lightning"></i>{{ t('docker.details.kill') }}</button>
                    <button type="button" :disabled="busy === container.id" :class="[btn, 'border-red-300 text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950']" @click="act('remove')"><i class="bi bi-trash"></i>{{ t('docker.shared.remove') }}</button>
                    <i v-if="busy === container.id" class="bi bi-arrow-repeat ml-1 inline-block animate-spin self-center text-slate-500"></i>
                </div>

                <div v-if="message" role="status" class="mt-3 whitespace-pre-line rounded-md border px-3 py-2 text-xs" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'">{{ message.text }}</div>
                <nav class="-mb-5 mt-4 flex gap-1 overflow-x-auto" :aria-label="t('docker.details.sections')">
                    <button v-for="tb in tabs" :key="tb.key" type="button" class="inline-flex shrink-0 items-center gap-1 border-b-2 px-3 py-2 text-xs font-medium" :class="current === tb.key ? 'border-indigo-500 text-indigo-600 dark:text-indigo-300' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'" @click="current = tb.key">
                        <i :class="tb.icon"></i>{{ tb.label }}
                    </button>
                </nav>
            </div>

            <div class="flex-1 overflow-y-auto p-5">
                <div v-if="error" class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</div>
                <p v-if="loading && !info && !['logs', 'console'].includes(current)" class="text-sm text-slate-500">{{ t('common.loading') }}</p>

                <div v-if="current === 'overview' && info" class="grid gap-4 md:grid-cols-2">
                    <dl class="space-y-2 rounded-lg border border-slate-200 p-4 text-sm dark:border-slate-800">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('docker.details.status') }}</dt><dd class="text-right">{{ container.status }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('docker.details.started') }}</dt><dd class="text-right">{{ fmtDate(info.state.started_at) }}</dd></div>
                        <div v-if="!info.state.running" class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('docker.containerList.filters.stopped') }}</dt><dd class="text-right">{{ t('docker.details.stoppedAt', { date: fmtDate(info.state.finished_at), code: info.state.exit_code }) }}</dd></div>
                        <div v-if="info.state.oom_killed" class="rounded bg-red-50 px-2 py-1 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ t('docker.details.oom') }}</div>
                        <div v-if="info.state.error" class="rounded bg-red-50 px-2 py-1 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ info.state.error }}</div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('docker.details.restarts') }}</dt><dd>{{ t('docker.details.restartPolicy', { count: info.restart_count, policy: info.restart_policy || 'no' }) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('docker.details.created') }}</dt><dd class="text-right">{{ fmtDate(info.created) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('docker.details.limits') }}</dt><dd>{{ info.memory ? `${info.memory} RAM` : t('docker.details.noRam') }} · {{ info.cpus ? `${info.cpus} CPU` : t('docker.details.noCpu') }}</dd></div>
                    </dl>
                    <div class="space-y-4">
                        <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('docker.containerList.ports') }}</h3>
                            <ul v-if="info.ports.length" class="mt-2 space-y-1 text-sm">
                                <li v-for="p in info.ports" :key="`${p.host}-${p.container}-${p.protocol}`" class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs">{{ p.public ? '0.0.0.0' : '127.0.0.1' }}:{{ p.host }} → {{ p.container }}/{{ p.protocol }}</span>
                                    <span v-if="p.public" class="rounded bg-amber-100 px-1.5 text-[11px] text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ t('docker.details.public') }}</span>
                                    <a v-if="portUrl(p)" :href="portUrl(p)" target="_blank" rel="noopener" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ t('docker.details.open') }}</a>
                                    <span v-else class="text-xs text-slate-500">{{ t('docker.details.localOnly') }}</span>
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm text-slate-500">{{ t('docker.details.noPorts') }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('docker.details.command') }}</h3>
                            <p class="mt-2 break-all font-mono text-xs">{{ info.command || '—' }}</p>
                            <p v-if="info.working_dir || info.user" class="mt-1 text-xs text-slate-500">
                                <span v-if="info.working_dir">{{ t('docker.details.workdir', { dir: info.working_dir }) }}</span> <span v-if="info.user">{{ t('docker.details.user', { user: info.user }) }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div v-if="current === 'env' && info">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-xs text-slate-500">{{ t('docker.details.envNote') }}</p>
                        <button type="button" :class="plain" @click="revealed = !revealed"><i :class="revealed ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>{{ revealed ? t('docker.details.hideValues') : t('docker.details.showValues') }}</button>
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-800">
                        <table class="min-w-full text-left text-xs">
                            <tbody>
                                <tr v-for="e in env" :key="e.key" class="border-t border-slate-100 first:border-0 dark:border-slate-800">
                                    <td class="px-3 py-1.5 font-mono font-medium">{{ e.key }}</td>
                                    <td class="break-all px-3 py-1.5 font-mono text-slate-600 dark:text-slate-300">{{ revealed ? e.value : (e.value ? '••••••••' : '') }}</td>
                                    <td class="px-2 text-right"><button type="button" class="text-slate-400 hover:text-slate-700" :aria-label="t('docker.details.copyValue')" @click="copy(e.value)"><i class="bi bi-clipboard"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="current === 'storage' && info">
                    <p v-if="!info.mounts.length" class="text-sm text-slate-500">{{ t('docker.details.noMounts') }}</p>
                    <ul v-else class="space-y-2">
                        <li v-for="m in info.mounts" :key="m.target" class="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-800">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded bg-slate-100 px-1.5 text-[11px] uppercase dark:bg-slate-800">{{ m.type }}</span>
                                <span class="font-mono text-xs">{{ m.target }}</span>
                                <span v-if="m.read_only" class="text-[11px] text-slate-500">{{ t('docker.details.readOnly') }}</span>
                            </div>
                            <p class="mt-1 break-all font-mono text-xs text-slate-500">{{ m.type === 'volume' ? t('docker.details.volume', { name: m.name }) : m.source }}</p>
                            <p v-if="m.type === 'bind' && isInsideBase(m.source)" class="mt-1 text-xs text-slate-500">{{ t('docker.details.bindNote') }}</p>
                        </li>
                    </ul>
                </div>

                <div v-if="current === 'network' && info" class="space-y-3">
                    <div v-if="netError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ netError }}</div>
                    <ul class="space-y-2">
                        <li v-for="n in info.networks" :key="n.name" class="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-800">
                            <div class="min-w-0">
                                <div class="font-medium">{{ n.name }} <span class="ml-1 font-mono text-xs text-slate-500">{{ n.ip }}</span></div>
                                <p class="mt-1 text-xs text-slate-500">{{ t('docker.details.reachAs') }} <span class="font-mono">{{ (n.dns_names.length ? n.dns_names : [container.name]).filter((x) => !/^[0-9a-f]{12}$/.test(x)).join(', ') || '—' }}</span></p>
                            </div>
                            <button type="button" :disabled="netBusy === `disconnect:${n.name}`" :class="plain" @click="network('disconnect', n.name)">{{ t('docker.networkCard.leave') }}</button>
                        </li>
                    </ul>
                    <form v-if="joinable.length" class="flex flex-wrap items-end gap-2 rounded-lg border border-dashed border-slate-300 p-3 dark:border-slate-700" @submit.prevent="network('connect', joinNetwork, joinAliases.split(/[\s,]+/).filter(Boolean))">
                        <label class="text-xs">
                            <span class="mb-1 block text-slate-500">{{ t('docker.details.join') }}</span>
                            <select v-model="joinNetwork" required class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="" disabled>{{ t('docker.details.choose') }}</option>
                                <option v-for="n in joinable" :key="n" :value="n">{{ n }}</option>
                            </select>
                        </label>
                        <label class="min-w-0 flex-1 text-xs">
                            <span class="mb-1 block text-slate-500">{{ t('docker.details.extraNames') }}</span>
                            <input v-model="joinAliases" type="text" placeholder="db, database" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        </label>
                        <button type="submit" :disabled="!joinNetwork || netBusy !== ''" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-700 disabled:opacity-40">{{ t('docker.details.joinBtn') }}</button>
                    </form>
                </div>

                <ContainerConsole v-if="current === 'console'" :container="container" :exec="docker.exec" :error-text="docker.errorText" />
                <LogViewer v-if="current === 'logs'" :fetch="fetchLogs" :source="container.id" :filename="`${container.name}.log`" :error-text="docker.errorText" />
            </div>
        </div>
    </Modal>
</template>
