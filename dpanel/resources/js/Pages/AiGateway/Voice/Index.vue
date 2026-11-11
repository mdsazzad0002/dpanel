<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';

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

const MODE_OPTIONS = [
    { value: 'first', label: 'Local first', hint: 'Whisper on this server for every voice note; live models only when it fails.' },
    { value: 'fallback', label: 'Live first', hint: 'Live transcription models first; local whisper only when they fail or none are set up.' },
    { value: 'off', label: 'Local off', hint: 'Live transcription models only. English translation of voice notes needs local whisper, so it stops.' },
];
const mode = ref(props.mode);

async function setMode(value) {
    if (value === mode.value) return;
    if (value === 'off' && !props.liveModels.length && !confirm('No live transcription model is set up (AI Gateway → Models). With local off, voice notes will not be transcribed. Turn it off anyway?')) return;
    busy.value = 'mode';
    error.value = notice.value = '';
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.voice.mode'), { mode: value });
        mode.value = data.mode;
        notice.value = `Voice to text: ${MODE_OPTIONS.find((m) => m.value === data.mode)?.label}.`;
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
        if (!list.some((r) => r.file === m.file)) list.push({ file: m.file, label: m.file, size_mb: m.size_mb, note: 'Added by hand', installed: true });
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
    if (row.installed && !confirm(`Delete ${row.file} (${row.size_mb} MB) from this server?`)) return;
    busy.value = `remove:${row.file}`;
    error.value = notice.value = '';
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.voice.remove'), { file: row.file });
        applyStatus(data);
        if (row.installed) notice.value = `${row.file} removed.`;
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
    return `${threads} thread${threads === 1 ? '' : 's'} → up to ${threads * 100}% CPU in top (100% = one core, this server has ${cpus.value} cores = ${cpus.value * 100}% max)`;
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
        notice.value = `Saved, ${data.status?.model} loaded in ${data.status?.load_ms ?? '?'} ms.`;
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
        notice.value = `Restarted, ${data.status?.model} loaded in ${data.status?.load_ms ?? '?'} ms.`;
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
            stream.getTracks().forEach((t) => t.stop());
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
        error.value = `Microphone not available: ${e.message}`;
    }
}

function stopRecording() {
    clearInterval(timer);
    recording.value = false;
    recorder?.state === 'recording' && recorder.stop();
}

async function runTest() {
    if (!test.file) {
        error.value = 'Choose an audio file or record one first.';
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
    <Head title="Voice to Text" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Voice to Text</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Whisper (whisper.cpp in drust) on this server turns voice notes into text for the AI, without a cloud API key.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="notice" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</div>
            <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</div>

            <!-- status -->
            <div :class="card" class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <div class="flex items-center gap-2 font-medium">
                    <span class="inline-block h-2.5 w-2.5 rounded-full" :class="status.reachable && status.speech_built ? 'bg-emerald-500' : 'bg-red-500'"></span>
                    {{ status.reachable ? (status.speech_built ? 'Running' : 'drust was built without speech support') : status.error }}
                </div>
                <template v-if="status.reachable">
                    <div><span class="text-slate-500">Model</span> {{ status.model }}
                        <span v-if="status.loaded" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">loaded</span>
                        <span v-else class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500 dark:bg-slate-700">loads on first use</span>
                    </div>
                    <div><span class="text-slate-500">Threads</span> {{ status.threads }} / {{ status.cpus }} cores</div>
                </template>
                <button type="button" @click="restart" :disabled="!!busy || !status.reachable"
                    class="ml-auto flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">
                    <i class="bi bi-arrow-clockwise" :class="busy === 'restart' && 'animate-spin'"></i> Restart
                </button>
            </div>

            <!-- local on/off -->
            <div :class="card">
                <h2 class="mb-2 text-sm font-semibold">Which voice to text goes first</h2>
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
                    Live transcription models:
                    <template v-if="liveModels.length">{{ liveModels.map((m) => `${m.name} (${m.provider})`).join(', ') }}</template>
                    <span v-else class="text-amber-600">none yet. Add one under AI Gateway → Models (e.g. whisper-large-v3-turbo on Groq) with the "transcription" capability.</span>
                </p>
            </div>

            <!-- model files -->
            <div :class="card">
                <h2 class="mb-2 text-sm font-semibold">Whisper models on this server</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs text-slate-500 dark:border-slate-700">
                                <th class="py-1.5 pr-3 font-medium">Model</th>
                                <th class="py-1.5 pr-3 font-medium">Size</th>
                                <th class="py-1.5 pr-3 font-medium">Notes</th>
                                <th class="py-1.5 text-right font-medium">Action</th>
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
                                        <span class="text-[11px]">Downloading {{ mb(r.pull.downloaded) }}<template v-if="r.pull.total"> / {{ mb(r.pull.total) }} MB · {{ percent(r.pull) }}%</template><template v-else> MB</template></span>
                                    </div>
                                    <div v-if="r.pull?.error" class="mt-1 text-[11px] text-red-600">{{ r.pull.error }}</div>
                                </td>
                                <td class="whitespace-nowrap py-2 text-right">
                                    <span v-if="r.inUse" class="mr-2 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">in use</span>
                                    <button v-if="!r.installed && (!r.pull || r.pull.error)" type="button" @click="pull(r.file)" :disabled="!!busy || !status.reachable"
                                        class="rounded-md bg-blue-600 px-2.5 py-1 text-xs text-white hover:bg-blue-700 disabled:opacity-50">
                                        <i class="bi bi-download mr-1"></i>{{ r.pull?.error ? 'Retry' : 'Download' }}
                                    </button>
                                    <button v-if="r.installed && !r.inUse" type="button" @click="settings.model = r.file; save()" :disabled="!!busy"
                                        class="ml-1 rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">Use</button>
                                    <button v-if="(r.installed && !r.inUse) || r.pull?.error" type="button" @click="remove(r)" :disabled="!!busy"
                                        class="ml-1 rounded-md border border-red-300 px-2.5 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:hover:bg-red-950">
                                        <i class="bi bi-trash mr-1"></i>{{ r.installed ? 'Remove' : 'Dismiss' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-500">Files come from <a href="https://huggingface.co/ggerganov/whisper.cpp" target="_blank" rel="noopener" class="text-blue-600 hover:underline">huggingface.co/ggerganov/whisper.cpp</a> into <code>/var/lib/drust/models</code>. Bigger models are more accurate but slower on CPU; try one with the test form below.</p>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- settings -->
                <form :class="card" class="space-y-3" @submit.prevent="save">
                    <h2 class="text-sm font-semibold">Model and CPU</h2>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Whisper model</label>
                        <select v-model="settings.model" :class="input">
                            <option v-for="m in status.models || []" :key="m.file" :value="m.file">{{ m.file }} ({{ m.size_mb }} MB)</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Bigger models are more accurate but slower. Files come from <code>/var/lib/drust/models</code>.</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">CPU threads (0 = automatic)</label>
                        <input v-model.number="settings.threads" type="number" min="0" :max="cpus" :class="input" />
                        <p class="mt-1 text-xs text-slate-500">{{ cpuNote }}. Fewer threads leave CPU for websites but transcribe slower. Voice notes are done one at a time.</p>
                    </div>
                    <button type="submit" :disabled="!!busy || !status.reachable"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        <i v-if="busy === 'save'" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>Save and restart
                    </button>
                </form>

                <!-- test form -->
                <form :class="card" class="space-y-3" @submit.prevent="runTest">
                    <h2 class="text-sm font-semibold">Test a voice note</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <input ref="fileInput" type="file" accept="audio/*,video/webm,video/mp4,.ogg,.opus,.m4a,.amr" @change="pickFile"
                            class="min-w-0 flex-1 text-sm file:mr-2 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm dark:file:bg-slate-700" />
                        <span class="text-xs text-slate-400">or</span>
                        <button v-if="!recording" type="button" @click="startRecording" :disabled="!!busy"
                            class="flex items-center gap-1.5 rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:hover:bg-red-950">
                            <i class="bi bi-mic-fill"></i> Record
                        </button>
                        <button v-else type="button" @click="stopRecording"
                            class="flex items-center gap-1.5 rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">
                            <i class="bi bi-stop-fill"></i> Stop ({{ recordSeconds }}s)
                        </button>
                    </div>
                    <audio v-if="recordedUrl" :src="recordedUrl" controls class="h-10 w-full"></audio>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium">Spoken language</label>
                            <select v-model="test.language" :class="input">
                                <option value="auto">Detect automatically</option>
                                <option value="bn">Bangla (bn)</option>
                                <option value="en">English (en)</option>
                                <option value="hi">Hindi (hi)</option>
                                <option value="ur">Urdu (ur)</option>
                                <option value="ar">Arabic (ar)</option>
                            </select>
                        </div>
                        <label class="mt-5 flex items-center gap-2 text-sm">
                            <input v-model="test.translate" type="checkbox" class="rounded border-slate-300" />
                            Translate to English
                        </label>
                    </div>
                    <button type="submit" :disabled="!!busy || recording || !test.file || !status.reachable"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        <i v-if="busy === 'test'" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ busy === 'test' ? 'Working…' : (test.translate ? 'Translate' : 'Transcribe') }}
                    </button>
                    <div v-if="result" class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900">
                        <p class="mb-1 text-xs text-slate-500">{{ result.translate ? 'English translation' : 'Transcript' }} · {{ result.model }} · whisper {{ fmtMs(result.ms) }} (total {{ fmtMs(result.totalMs) }})</p>
                        <p class="whitespace-pre-wrap text-sm">{{ result.text || '(nothing heard)' }}</p>
                    </div>
                </form>
            </div>

            <!-- recent -->
            <div :class="card">
                <h2 class="mb-2 text-sm font-semibold">Recent voice notes (all transcription requests)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs text-slate-500 dark:border-slate-700">
                                <th class="py-1.5 pr-3 font-medium">When</th>
                                <th class="py-1.5 pr-3 font-medium">Model</th>
                                <th class="py-1.5 pr-3 font-medium">Time</th>
                                <th class="py-1.5 font-medium">Text</th>
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
                                <td colspan="4" class="py-4 text-center text-xs text-slate-400">No voice notes yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
