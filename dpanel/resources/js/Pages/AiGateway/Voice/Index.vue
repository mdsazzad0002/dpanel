<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    status: { type: Object, required: true },
    mode: { type: String, default: 'first' },
    recent: { type: Array, default: () => [] },
    localModel: { type: String, default: '' },
    catalog: { type: Array, default: () => [] },
    liveModels: { type: Array, default: () => [] },
});

const status = ref(props.status);
const error = ref('');
const notice = ref('');
const busy = ref('');

const MODE_OPTIONS = computed(() => [
    { value: 'first', label: t('aiGateway.voice.mode.first'), hint: t('aiGateway.voice.mode.firstHint') },
    { value: 'fallback', label: t('aiGateway.voice.mode.fallback'), hint: t('aiGateway.voice.mode.fallbackHint') },
    { value: 'off', label: t('aiGateway.voice.mode.off'), hint: t('aiGateway.voice.mode.offHint') },
]);
const mode = ref(props.mode);

async function setMode(value) {
    if (value === mode.value) return;
    if (value === 'off' && !props.liveModels.length && !confirm(t('aiGateway.voice.offConfirm'))) return;
    busy.value = 'mode';
    error.value = notice.value = '';
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.voice.mode'), { mode: value });
        mode.value = data.mode;
        notice.value = t('aiGateway.voice.modeSaved', { mode: MODE_OPTIONS.value.find((m) => m.value === data.mode)?.label });
    } catch (e) {
        error.value = e.response?.data?.message || e.message;
    } finally {
        busy.value = '';
    }
}

// --- model files: download / remove ---
const rows = computed(() => {
    const installed = new Map((status.value.models || []).map((m) => [m.file, m]));
    const pulls = new Map((status.value.pulls || []).map((p) => [p.file, p]));
    const list = props.catalog.map((c) => ({ ...c, installed: installed.has(c.file) }));
    for (const m of installed.values()) {
        if (!list.some((r) => r.file === m.file)) list.push({ file: m.file, label: m.file, size_mb: m.size_mb, note: t('aiGateway.voice.addedByHand'), installed: true });
    }
    return list.map((r) => ({ ...r, pull: pulls.get(r.file) || null, inUse: status.value.model === r.file }));
});
const downloading = computed(() => (status.value.pulls || []).some((p) => !p.error));
const percent = (p) => (p.total ? Math.floor((p.downloaded / p.total) * 100) : null);
const mb = (bytes) => Math.round(bytes / 1048576);

let poll = null;
function watchDownloads() {
    if (poll || !downloading.value) return;
    poll = setInterval(async () => {
        try {
            const { data } = await axios.get(panelRoute('ai-gateway.voice.state'));
            applyStatus(data);
        } catch (e) { /* keep polling */ }
        if (!downloading.value) {
            clearInterval(poll);
            poll = null;
        }
    }, 2000);
}

async function pull(file) {
    busy.value = `pull:${file}`;
    error.value = notice.value = '';
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.voice.pull'), { file });
        applyStatus(data);
        watchDownloads();
    } catch (e) {
        error.value = e.response?.data?.message || e.message;
    } finally {
        busy.value = '';
    }
}

async function remove(row) {
    if (row.installed && !confirm(t('aiGateway.voice.deleteConfirm', { file: row.file, size: row.size_mb }))) return;
    busy.value = `remove:${row.file}`;
    error.value = notice.value = '';
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.voice.remove'), { file: row.file });
        applyStatus(data);
        if (row.installed) notice.value = t('aiGateway.voice.removed', { file: row.file });
    } catch (e) {
        error.value = e.response?.data?.message || e.message;
    } finally {
        busy.value = '';
    }
}

// --- model + threads ---
const settings = reactive({
    model: props.status.model || '',
    threads: props.status.threads_setting || 0,
});
const cpus = computed(() => status.value.cpus || 1);
const cpuNote = computed(() => {
    const threads = settings.threads > 0 ? settings.threads : Math.min(cpus.value, 8);
    return t('aiGateway.voice.cpuNote', { threads, pct: threads * 100, cores: cpus.value, max: cpus.value * 100 });
});

function applyStatus(data) {
    if (data?.status) status.value = { reachable: true, ...data.status };
}

async function save() {
    busy.value = 'save';
    error.value = notice.value = '';
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.voice.save'), settings);
        applyStatus(data);
        notice.value = t('aiGateway.voice.saved', { model: data.status?.model, ms: data.status?.load_ms ?? '?' });
    } catch (e) {
        error.value = e.response?.data?.message || e.message;
        applyStatus(e.response?.data);
    } finally {
        busy.value = '';
    }
}

async function restart() {
    busy.value = 'restart';
    error.value = notice.value = '';
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.voice.restart'));
        applyStatus(data);
        notice.value = t('aiGateway.voice.restarted', { model: data.status?.model, ms: data.status?.load_ms ?? '?' });
    } catch (e) {
        error.value = e.response?.data?.message || e.message;
        applyStatus(e.response?.data);
    } finally {
        busy.value = '';
    }
}

// --- test form: upload or record ---
const test = reactive({ file: null, language: 'auto', translate: false });
const result = ref(null);
const fileInput = ref(null);
const recording = ref(false);
const recordSeconds = ref(0);
const recordedUrl = ref('');
let recorder = null;
let timer = null;

function pickFile(event) {
    test.file = event.target.files?.[0] || null;
    if (recordedUrl.value) URL.revokeObjectURL(recordedUrl.value);
    recordedUrl.value = test.file ? URL.createObjectURL(test.file) : '';
}

async function startRecording() {
    error.value = '';
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const chunks = [];
        recorder = new MediaRecorder(stream);
        recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
        recorder.onstop = () => {
            stream.getTracks().forEach((track) => track.stop());
            const type = recorder.mimeType || 'audio/webm';
            const ext = type.includes('ogg') ? 'ogg' : (type.includes('mp4') ? 'm4a' : 'webm');
            test.file = new File([new Blob(chunks, { type })], `recording.${ext}`, { type });
            if (recordedUrl.value) URL.revokeObjectURL(recordedUrl.value);
            recordedUrl.value = URL.createObjectURL(test.file);
            if (fileInput.value) fileInput.value.value = '';
        };
        recorder.start();
        recording.value = true;
        recordSeconds.value = 0;
        timer = setInterval(() => { recordSeconds.value += 1; }, 1000);
    } catch (e) {
        error.value = t('aiGateway.voice.noMic', { error: e.message });
    }
}

function stopRecording() {
    clearInterval(timer);
    recording.value = false;
    recorder?.state === 'recording' && recorder.stop();
}

async function runTest() {
    if (!test.file) {
        error.value = t('aiGateway.voice.chooseFile');
        return;
    }
    busy.value = 'test';
    error.value = notice.value = '';
    result.value = null;
    const started = performance.now();
    const form = new FormData();
    form.append('file', test.file);
    form.append('language', test.language);
    form.append('translate', test.translate ? '1' : '0');
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.voice.test'), form, { timeout: 330000 });
        applyStatus(data);
        result.value = {
            text: data.text,
            ms: data.ms,
            totalMs: Math.round(performance.now() - started),
            translate: test.translate,
            model: status.value.model,
        };
    } catch (e) {
        error.value = e.response?.data?.message || e.message;
        applyStatus(e.response?.data);
    } finally {
        busy.value = '';
    }
}

watchDownloads();

onBeforeUnmount(() => {
    clearInterval(poll);
    stopRecording();
    if (recordedUrl.value) URL.revokeObjectURL(recordedUrl.value);
});

const fmtMs = (ms) => (ms == null ? '' : (ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${ms} ms`));
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString() : '');
const card = 'rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800';
const input = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900';
</script>

<template>
    <Head :title="t('aiGateway.voice.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('aiGateway.voice.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('aiGateway.voice.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="notice" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</div>
            <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</div>

            <!-- status -->
            <div :class="card" class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <div class="flex items-center gap-2 font-medium">
                    <span class="inline-block h-2.5 w-2.5 rounded-full" :class="status.reachable && status.speech_built ? 'bg-emerald-500' : 'bg-red-500'"></span>
                    {{ status.reachable ? (status.speech_built ? t('aiGateway.voice.running') : t('aiGateway.voice.noSpeech')) : status.error }}
                </div>
                <template v-if="status.reachable">
                    <div><span class="text-slate-500">{{ t('aiGateway.voice.model') }}</span> {{ status.model }}
                        <span v-if="status.loaded" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">{{ t('aiGateway.voice.loaded') }}</span>
                        <span v-else class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500 dark:bg-slate-700">{{ t('aiGateway.voice.loadsOnUse') }}</span>
                    </div>
                    <div><span class="text-slate-500">{{ t('aiGateway.voice.threads') }}</span> {{ t('aiGateway.voice.threadsOf', { threads: status.threads, cpus: status.cpus }) }}</div>
                </template>
                <button type="button" @click="restart" :disabled="!!busy || !status.reachable"
                    class="ml-auto flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">
                    <i class="bi bi-arrow-clockwise" :class="busy === 'restart' && 'animate-spin'"></i> {{ t('aiGateway.voice.restart') }}
                </button>
            </div>

            <!-- local on/off -->
            <div :class="card">
                <h2 class="mb-2 text-sm font-semibold">{{ t('aiGateway.voice.whichFirst') }}</h2>
                <div class="grid gap-2 sm:grid-cols-3">
                    <button v-for="o in MODE_OPTIONS" :key="o.value" type="button" @click="setMode(o.value)" :disabled="!!busy"
                        class="rounded-md border p-3 text-left text-sm disabled:opacity-60"
                        :class="mode === o.value ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-950' : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-700/50'">
                        <span class="flex items-center gap-2 font-medium">
                            <i class="bi" :class="mode === o.value ? 'bi-record-circle-fill text-blue-600' : 'bi-circle text-slate-400'"></i>{{ o.label }}
                        </span>
                        <span class="mt-1 block text-xs text-slate-500">{{ o.hint }}</span>
                    </button>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    {{ t('aiGateway.voice.liveModels') }}
                    <template v-if="liveModels.length">{{ liveModels.map((m) => `${m.name} (${m.provider})`).join(', ') }}</template>
                    <span v-else class="text-amber-600">{{ t('aiGateway.voice.liveNone') }}</span>
                </p>
            </div>

            <!-- model files -->
            <div :class="card">
                <h2 class="mb-2 text-sm font-semibold">{{ t('aiGateway.voice.modelsTitle') }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs text-slate-500 dark:border-slate-700">
                                <th class="py-1.5 pr-3 font-medium">{{ t('aiGateway.voice.model') }}</th>
                                <th class="py-1.5 pr-3 font-medium">{{ t('aiGateway.voice.col.size') }}</th>
                                <th class="py-1.5 pr-3 font-medium">{{ t('aiGateway.voice.col.notes') }}</th>
                                <th class="py-1.5 text-right font-medium">{{ t('aiGateway.voice.col.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.file" class="border-b border-slate-100 align-middle dark:border-slate-700/50">
                                <td class="py-2 pr-3">
                                    <span class="font-medium">{{ r.label }}</span>
                                    <span class="block text-[11px] text-slate-400">{{ r.file }}</span>
                                </td>
                                <td class="whitespace-nowrap py-2 pr-3 text-xs">{{ r.size_mb }} MB</td>
                                <td class="py-2 pr-3 text-xs text-slate-500">
                                    {{ r.note }}
                                    <div v-if="r.pull && !r.pull.error" class="mt-1">
                                        <div class="h-1.5 w-full overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
                                            <div class="h-full bg-blue-600 transition-all" :style="{ width: (percent(r.pull) ?? 5) + '%' }"></div>
                                        </div>
                                        <span class="text-[11px]"><template v-if="r.pull.total">{{ t('aiGateway.voice.downloadingOf', { done: mb(r.pull.downloaded), total: mb(r.pull.total), pct: percent(r.pull) }) }}</template><template v-else>{{ t('aiGateway.voice.downloading', { done: mb(r.pull.downloaded) }) }}</template></span>
                                    </div>
                                    <div v-if="r.pull?.error" class="mt-1 text-[11px] text-red-600">{{ r.pull.error }}</div>
                                </td>
                                <td class="whitespace-nowrap py-2 text-right">
                                    <span v-if="r.inUse" class="mr-2 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">{{ t('aiGateway.voice.inUse') }}</span>
                                    <button v-if="!r.installed && (!r.pull || r.pull.error)" type="button" @click="pull(r.file)" :disabled="!!busy || !status.reachable"
                                        class="rounded-md bg-blue-600 px-2.5 py-1 text-xs text-white hover:bg-blue-700 disabled:opacity-50">
                                        <i class="bi bi-download mr-1"></i>{{ r.pull?.error ? t('aiGateway.voice.retry') : t('aiGateway.voice.download') }}
                                    </button>
                                    <button v-if="r.installed && !r.inUse" type="button" @click="settings.model = r.file; save()" :disabled="!!busy"
                                        class="ml-1 rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">{{ t('aiGateway.voice.use') }}</button>
                                    <button v-if="(r.installed && !r.inUse) || r.pull?.error" type="button" @click="remove(r)" :disabled="!!busy"
                                        class="ml-1 rounded-md border border-red-300 px-2.5 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:hover:bg-red-950">
                                        <i class="bi bi-trash mr-1"></i>{{ r.installed ? t('aiGateway.voice.remove') : t('aiGateway.voice.dismiss') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-500"><i18n-t keypath="aiGateway.voice.filesFrom" scope="global"><template #link><a href="https://huggingface.co/ggerganov/whisper.cpp" target="_blank" rel="noopener" class="text-blue-600 hover:underline">huggingface.co/ggerganov/whisper.cpp</a></template><template #dir><code>/var/lib/drust/models</code></template></i18n-t></p>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- settings -->
                <form :class="card" class="space-y-3" @submit.prevent="save">
                    <h2 class="text-sm font-semibold">{{ t('aiGateway.voice.settingsTitle') }}</h2>
                    <div>
                        <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.voice.whisperModel') }}</label>
                        <select v-model="settings.model" :class="input">
                            <option v-for="m in status.models || []" :key="m.file" :value="m.file">{{ m.file }} ({{ m.size_mb }} MB)</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500"><i18n-t keypath="aiGateway.voice.biggerHelp" scope="global"><template #dir><code>/var/lib/drust/models</code></template></i18n-t></p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.voice.cpuThreads') }}</label>
                        <input v-model.number="settings.threads" type="number" min="0" :max="cpus" :class="input" />
                        <p class="mt-1 text-xs text-slate-500">{{ cpuNote }}. {{ t('aiGateway.voice.threadsHelp') }}</p>
                    </div>
                    <button type="submit" :disabled="!!busy || !status.reachable"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        <i v-if="busy === 'save'" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ t('aiGateway.voice.saveRestart') }}
                    </button>
                </form>

                <!-- test form -->
                <form :class="card" class="space-y-3" @submit.prevent="runTest">
                    <h2 class="text-sm font-semibold">{{ t('aiGateway.voice.testTitle') }}</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <input ref="fileInput" type="file" accept="audio/*,video/webm,video/mp4,.ogg,.opus,.m4a,.amr" @change="pickFile"
                            class="min-w-0 flex-1 text-sm file:mr-2 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm dark:file:bg-slate-700" />
                        <span class="text-xs text-slate-400">{{ t('aiGateway.voice.or') }}</span>
                        <button v-if="!recording" type="button" @click="startRecording" :disabled="!!busy"
                            class="flex items-center gap-1.5 rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:hover:bg-red-950">
                            <i class="bi bi-mic-fill"></i> {{ t('aiGateway.voice.record') }}
                        </button>
                        <button v-else type="button" @click="stopRecording"
                            class="flex items-center gap-1.5 rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">
                            <i class="bi bi-stop-fill"></i> {{ t('aiGateway.voice.stop', { s: recordSeconds }) }}
                        </button>
                    </div>
                    <audio v-if="recordedUrl" :src="recordedUrl" controls class="h-10 w-full"></audio>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.voice.language') }}</label>
                            <select v-model="test.language" :class="input">
                                <option value="auto">{{ t('aiGateway.voice.lang.auto') }}</option>
                                <option value="bn">{{ t('aiGateway.voice.lang.bn') }} (bn)</option>
                                <option value="en">{{ t('aiGateway.voice.lang.en') }} (en)</option>
                                <option value="hi">{{ t('aiGateway.voice.lang.hi') }} (hi)</option>
                                <option value="ur">{{ t('aiGateway.voice.lang.ur') }} (ur)</option>
                                <option value="ar">{{ t('aiGateway.voice.lang.ar') }} (ar)</option>
                            </select>
                        </div>
                        <label class="mt-5 flex items-center gap-2 text-sm">
                            <input v-model="test.translate" type="checkbox" class="rounded border-slate-300" />
                            {{ t('aiGateway.voice.translateEn') }}
                        </label>
                    </div>
                    <button type="submit" :disabled="!!busy || recording || !test.file || !status.reachable"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        <i v-if="busy === 'test'" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ busy === 'test' ? t('aiGateway.voice.working') : (test.translate ? t('aiGateway.voice.translate') : t('aiGateway.voice.transcribe')) }}
                    </button>
                    <div v-if="result" class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900">
                        <p class="mb-1 text-xs text-slate-500">{{ result.translate ? t('aiGateway.voice.englishTranslation') : t('aiGateway.voice.transcript') }} · {{ result.model }} · {{ t('aiGateway.voice.timing', { ms: fmtMs(result.ms), total: fmtMs(result.totalMs) }) }}</p>
                        <p class="whitespace-pre-wrap text-sm">{{ result.text || t('aiGateway.voice.nothingHeard') }}</p>
                    </div>
                </form>
            </div>

            <!-- recent -->
            <div :class="card">
                <h2 class="mb-2 text-sm font-semibold">{{ t('aiGateway.voice.recent') }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs text-slate-500 dark:border-slate-700">
                                <th class="py-1.5 pr-3 font-medium">{{ t('aiGateway.voice.col.when') }}</th>
                                <th class="py-1.5 pr-3 font-medium">{{ t('aiGateway.voice.model') }}</th>
                                <th class="py-1.5 pr-3 font-medium">{{ t('aiGateway.voice.col.time') }}</th>
                                <th class="py-1.5 font-medium">{{ t('aiGateway.voice.col.text') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in recent" :key="r.id" class="border-b border-slate-100 align-top dark:border-slate-700/50">
                                <td class="whitespace-nowrap py-1.5 pr-3 text-xs text-slate-500">{{ fmtDate(r.created_at) }}</td>
                                <td class="whitespace-nowrap py-1.5 pr-3 text-xs">
                                    {{ r.model }}
                                    <span v-if="r.status !== 'success'" class="ml-1 text-red-600">{{ r.status }}</span>
                                </td>
                                <td class="whitespace-nowrap py-1.5 pr-3 text-xs text-slate-500">{{ fmtMs(r.latency_ms) }}</td>
                                <td class="py-1.5 text-xs">{{ r.text }}</td>
                            </tr>
                            <tr v-if="!recent.length">
                                <td colspan="4" class="py-4 text-center text-xs text-slate-400">{{ t('aiGateway.voice.noRecent') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
