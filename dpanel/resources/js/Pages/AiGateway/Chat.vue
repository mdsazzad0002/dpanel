<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MarkdownContent from '@/Components/MarkdownContent.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const AUTO = '__auto__';
const MODEL_AUTO = '__auto_model__';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);
const currentUserId = computed(() => page.props.auth?.user?.id ?? null);

const props = defineProps({
    providers: { type: Array, default: () => [] },
    initialProviderId: { type: [Number, String], default: null },
    canUseServerTools: { type: Boolean, default: false },
});

// Server tools let the model call the MCP tools (logs, files, commands) on
// the real server. Read-only by default, since people come here to ask about
// the system; remembered per browser, and full access asks once each time.
const TOOLS_KEY = 'dpanel.aiChat.serverTools';
const readToolsMode = () => {
    try {
        return localStorage.getItem(TOOLS_KEY) === 'off' ? 'off' : 'read';
    } catch {
        return 'read';
    }
};
const toolsMode = ref(props.canUseServerTools ? readToolsMode() : 'off');
let previousToolsMode = toolsMode.value;
const onToolsModeChange = () => {
    if (toolsMode.value === 'full' && !confirm('Full access lets the AI change this server: edit files, run commands and restart services. Every change is recorded and files are backed up. Continue?')) {
        toolsMode.value = previousToolsMode;
        return;
    }
    previousToolsMode = toolsMode.value;
    try {
        localStorage.setItem(TOOLS_KEY, toolsMode.value);
    } catch {
        // Not remembered; still applies to this page.
    }
};
// Icon and readable name per server tool; unknown tools fall back to their raw name.
const TOOL_META = {
    get_panel_overview: ['bi-speedometer2', 'Server overview'],
    list_servers: ['bi-hdd-network', 'List servers'],
    list_websites: ['bi-globe2', 'List websites'],
    get_website: ['bi-globe', 'Website details'],
    check_website: ['bi-activity', 'Live website check'],
    list_ssl_certificates: ['bi-shield-lock', 'SSL certificates'],
    list_dns_records: ['bi-signpost-split', 'DNS records'],
    list_databases: ['bi-database', 'Databases'],
    list_cron_jobs: ['bi-clock-history', 'Cron jobs'],
    get_mail_status: ['bi-envelope-check', 'Mail status'],
    list_backups: ['bi-archive', 'Backups'],
    list_security_findings: ['bi-shield-exclamation', 'Security findings'],
    get_service_status: ['bi-cpu', 'Service status'],
    read_service_logs: ['bi-journal-text', 'Service logs'],
    read_log_file: ['bi-file-earmark-text', 'Read log'],
    list_recent_commands: ['bi-terminal', 'Recent commands'],
    list_recent_activity: ['bi-list-check', 'Recent activity'],
    find_website_errors: ['bi-bug', 'Find website errors'],
    list_directory: ['bi-folder2-open', 'List directory'],
    read_file: ['bi-file-earmark-code', 'Read file'],
    search_files: ['bi-search', 'Search files'],
    analyze_disk_usage: ['bi-pie-chart', 'Disk usage'],
    write_file: ['bi-pencil-square', 'Write file'],
    edit_file: ['bi-pencil', 'Edit file'],
    file_operation: ['bi-files', 'File operation'],
    run_command: ['bi-terminal-fill', 'Run command'],
    restart_service: ['bi-arrow-clockwise', 'Restart service'],
};
const toolIcon = (step) => TOOL_META[step.name]?.[0] ?? 'bi-tools';
const toolLabel = (step) => {
    const args = step.arguments && typeof step.arguments === 'object' ? step.arguments : {};
    const main = args.path || args.domain || args.command || args.service || args.pattern || args.name || '';
    const title = TOOL_META[step.name]?.[1] ?? step.name;
    return main ? `${title} · ${String(main).slice(0, 80)}` : title;
};
// Long tool runs fold into one summary row once finished; open while running.
const toolSummary = (tools) => {
    const seconds = tools.reduce((sum, t) => sum + (t.duration_ms ?? 0), 0) / 1000;
    const failed = tools.filter((t) => t.status === 'error').length;
    return `Used ${tools.length} tool${tools.length === 1 ? '' : 's'} · ${seconds.toFixed(1)}s${failed ? ` · ${failed} failed` : ''}`;
};
const toolsFolded = (m) => m.tools.length > 3 && !m.toolsOpen && !m.tools.some((t) => t.status === 'running');

const providerId = ref(props.initialProviderId ?? props.providers[0]?.id ?? null);
const isAuto = computed(() => providerId.value === AUTO);
const provider = computed(() => (isAuto.value ? null : props.providers.find((p) => p.id === providerId.value) ?? null));

const canAuto = computed(() => props.providers.length > 1);

const hasCap = (m, cap) => (m?.capabilities || []).includes(cap);
const isImageOnly = (m) => hasCap(m, 'image_generation') && !hasCap(m, 'chat');

// Chat mode answers messages; image mode sends the prompt to an image model.
const mode = ref('chat');

const availableModels = computed(() => {
    const fits = (m) => (mode.value === 'image' ? hasCap(m, 'image_generation') : !isImageOnly(m));
    if (!isAuto.value) return (provider.value?.models || []).filter(fits);

    const seen = new Map();
    for (const p of props.providers) {
        for (const m of p.models || []) {
            if (fits(m) && !seen.has(m.name)) seen.set(m.name, m);
        }
    }
    return [...seen.values()];
});
const hasImageModels = computed(() => props.providers.some((p) => (p.models || []).some((m) => hasCap(m, 'image_generation'))));

const model = ref('');
const sessionId = ref(null);
const sessionOwnerId = ref(null);
const messages = ref([]);
const input = ref('');
const sending = ref(false);
const error = ref('');
const scrollEl = ref(null);
const inputEl = ref(null);
let abortController = null;
let stoppedByUser = false;
let isNewPendingSession = false;

const history = ref([]);
const historyLoading = ref(true);
const historySearch = ref('');
const sidebarOpen = ref(false);
const filteredHistory = computed(() => {
    const q = historySearch.value.trim().toLowerCase();
    return q ? history.value.filter((s) => (s.title || '').toLowerCase().includes(q)) : history.value;
});

const readOnly = computed(() => sessionId.value && sessionOwnerId.value && currentUserId.value && sessionOwnerId.value !== currentUserId.value);

// While streaming, only follow the output if the reader is already near the
// bottom, so scrolling up to read earlier text is not yanked back down.
const scrollToBottom = (force = true) => {
    const el = scrollEl.value;
    if (!el) return;
    const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
    nextTick(() => {
        if (force || nearBottom) el.scrollTop = el.scrollHeight;
    });
};

const autoGrow = () => {
    const el = inputEl.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
};
watch(input, () => nextTick(autoGrow));

const copiedIndex = ref(null);
const copyMessage = async (m, i) => {
    try {
        await navigator.clipboard.writeText(m.content);
        copiedIndex.value = i;
        setTimeout(() => { if (copiedIndex.value === i) copiedIndex.value = null; }, 1500);
    } catch {
        // Clipboard API unavailable — the text is still selectable.
    }
};

const stop = () => {
    stoppedByUser = true;
    abortController?.abort();
};

const useSuggestion = (text) => {
    input.value = text;
    nextTick(() => inputEl.value?.focus());
};
const suggestions = computed(() => (mode.value === 'image'
    ? ['A minimalist logo for a hosting company, flat vector', 'A cozy server room at night, cinematic lighting']
    : props.canUseServerTools && toolsMode.value !== 'off'
        ? ['Run a full health check of this server', 'Why is my website slow or down?', 'What is filling up the disk?', 'Check mail delivery and DNS for my domains', 'Are there any security issues?', 'Are my backups working?']
        : ['Explain how to set up a cron job in Laravel', 'Write a bash script to back up a MySQL database', 'Explain how a reverse proxy routes traffic to PHP-FPM']));

// Images waiting to go with the next message: [{ id, owner, preview, uploading }].
const pendingImages = ref([]);
const fileInput = ref(null);
const uploading = computed(() => pendingImages.value.some((img) => img.uploading));
const MAX_IMAGES = 8;

const mediaUrl = (id, owner) => panelRoute('ai-gateway.media.show', { media: id, owner: owner ?? sessionOwnerId.value ?? currentUserId.value });

// Image viewer: the clicked message's images, browsable with arrows.
const viewer = ref({ show: false, images: [], index: 0 });
const openViewer = (images, index) => {
    viewer.value = { show: true, images, index };
};
const stepViewer = (delta) => {
    const { images, index } = viewer.value;
    if (images.length > 1) viewer.value.index = (index + delta + images.length) % images.length;
};
const onViewerKeydown = (e) => {
    if (!viewer.value.show) return;
    if (e.key === 'ArrowLeft') stepViewer(-1);
    if (e.key === 'ArrowRight') stepViewer(1);
};
const viewerUrl = computed(() => (viewer.value.images.length ? mediaUrl(viewer.value.images[viewer.value.index]) : ''));

const attachFiles = async (files) => {
    for (const file of [...files].filter((f) => f.type.startsWith('image/'))) {
        if (pendingImages.value.length >= MAX_IMAGES) {
            error.value = `At most ${MAX_IMAGES} images per message.`;
            break;
        }
        const entry = { id: null, owner: currentUserId.value, preview: URL.createObjectURL(file), uploading: true };
        pendingImages.value.push(entry);
        const item = pendingImages.value[pendingImages.value.length - 1];
        try {
            const body = new FormData();
            body.append('image', file);
            const { data } = await axios.post(panelRoute('ai-gateway.media.upload'), body);
            item.id = data.id;
            item.uploading = false;
        } catch (e) {
            error.value = e.response?.data?.message || 'Could not upload that image.';
            removePending(item);
        }
    }
};
const removePending = (item) => {
    URL.revokeObjectURL(item.preview);
    pendingImages.value = pendingImages.value.filter((img) => img !== item);
};
const onPickFiles = (e) => {
    attachFiles(e.target.files);
    e.target.value = '';
};
const onPaste = (e) => {
    const files = [...(e.clipboardData?.files || [])];
    if (files.length && mode.value === 'chat') {
        e.preventDefault();
        attachFiles(files);
    }
};
const onDrop = (e) => {
    if (mode.value === 'chat' && !readOnly.value) attachFiles(e.dataTransfer?.files || []);
};

// A pinned model that cannot see images gets their OCR text instead.
const selectedModel = computed(() => availableModels.value.find((m) => m.name === model.value) || null);
const imagesWillBeOcr = computed(() => pendingImages.value.length > 0 && !!selectedModel.value && !hasCap(selectedModel.value, 'vision'));

const setMode = (value) => {
    if (mode.value === value || sending.value) return;
    mode.value = value;
    model.value = MODEL_AUTO;
    pendingImages.value.forEach((img) => URL.revokeObjectURL(img.preview));
    pendingImages.value = [];
};

const canSend = computed(() => (input.value.trim().length > 0 || (mode.value === 'chat' && pendingImages.value.length > 0))
    && !sending.value && !uploading.value && (isAuto.value || !!provider.value) && !readOnly.value);

// Route helpers: auto mode hits provider-agnostic endpoints, otherwise the
// existing provider-scoped ones.
const streamRoute = () => (isAuto.value
    ? panelRoute('ai-gateway.chat.auto.stream')
    : panelRoute('ai-gateway.providers.chat.stream', { provider: provider.value.id }));

const historyIndexRoute = () => (isAuto.value
    ? panelRoute('ai-gateway.chat.auto.history.index')
    : panelRoute('ai-gateway.providers.chat.history.index', { provider: provider.value.id }));

const historyShowRoute = (session) => (isAuto.value
    ? panelRoute('ai-gateway.chat.auto.history.show', { session: session.id, owner: session.owner_id })
    : panelRoute('ai-gateway.providers.chat.history.show', { provider: provider.value.id, session: session.id, owner: session.owner_id }));

const historyDestroyRoute = (session) => (isAuto.value
    ? panelRoute('ai-gateway.chat.auto.history.destroy', { session: session.id, owner: session.owner_id })
    : panelRoute('ai-gateway.providers.chat.history.destroy', { provider: provider.value.id, session: session.id, owner: session.owner_id }));

const historySaveRoute = () => (isAuto.value
    ? panelRoute('ai-gateway.chat.auto.history.save')
    : panelRoute('ai-gateway.providers.chat.history.save', { provider: provider.value.id }));

const loadHistory = async () => {
    if (!isAuto.value && !provider.value) {
        history.value = [];
        historyLoading.value = false;
        return;
    }

    historyLoading.value = true;
    try {
        const { data } = await axios.get(historyIndexRoute());
        history.value = data.sessions;
    } catch (e) {
        // Non-fatal — chat still works without a history list.
    } finally {
        historyLoading.value = false;
    }
};

const newChat = () => {
    if (sending.value) abortController?.abort();
    sessionId.value = null;
    sessionOwnerId.value = null;
    messages.value = [];
    error.value = '';
    input.value = '';
    sending.value = false;
    sidebarOpen.value = false;
    nextTick(() => inputEl.value?.focus());
};

const onProviderChange = () => {
    model.value = MODEL_AUTO;
    loadHistory();
};

const openChat = async (session) => {
    if (sending.value && session.id === sessionId.value) return;
    if (sending.value) abortController?.abort();

    try {
        const { data } = await axios.get(historyShowRoute(session));
        sessionId.value = data.id;
        sessionOwnerId.value = data.owner_id;
        messages.value = data.messages;
        error.value = '';
        sending.value = false;
        sidebarOpen.value = false;
        scrollToBottom();
    } catch (e) {
        error.value = 'Could not load that chat.';
    }
};

const deleteChat = async (session, e) => {
    e.stopPropagation();
    if (!confirm(`Delete chat "${session.title}"?`)) return;

    if (session.id === sessionId.value && sending.value) {
        abortController?.abort();
    }

    try {
        await axios.delete(historyDestroyRoute(session));
        history.value = history.value.filter((s) => s.id !== session.id);
        if (sessionId.value === session.id) newChat();
    } catch (e) {
        error.value = 'Could not delete that chat.';
    }
};

const upsertHistoryEntry = (entry) => {
    const idx = history.value.findIndex((s) => s.id === entry.id);
    if (idx === -1) {
        history.value.unshift(entry);
    } else {
        history.value.splice(idx, 1, { ...history.value[idx], ...entry });
        history.value.sort((a, b) => (a.updated_at < b.updated_at ? 1 : -1));
    }
};

const persist = async () => {
    try {
        const { data } = await axios.post(historySaveRoute(), {
            session_id: sessionId.value,
            messages: messages.value.map((m) => ({ role: m.role, content: m.content, ...(m.images?.length ? { images: m.images } : {}) })),
        });
        sessionId.value = data.id;
        sessionOwnerId.value = data.owner_id ?? currentUserId.value;
        upsertHistoryEntry({ ...data, running: false });
    } catch (e) {
        // Non-fatal — the conversation is still usable even if saving history fails.
    }
};

const generateImage = async () => {
    const prompt = input.value.trim();
    startSession(prompt);
    messages.value.push({ role: 'user', content: prompt });
    input.value = '';
    sending.value = true;
    messages.value.push({ role: 'assistant', content: '', images: [], generating: true });
    const assistantMessage = messages.value[messages.value.length - 1];
    scrollToBottom();

    try {
        const { data } = await axios.post(panelRoute('ai-gateway.images.generate'), {
            prompt,
            model: model.value === MODEL_AUTO ? null : model.value,
            provider_id: isAuto.value ? null : provider.value?.id,
        });
        Object.assign(assistantMessage, {
            content: data.text || 'Here is the generated image.',
            images: data.images.map((img) => img.id),
            model: data.model,
            providerName: data.provider_name,
            generating: false,
        });
        isNewPendingSession = false;
        await persist();
    } catch (e) {
        error.value = e.response?.data?.message || e.message || 'Image generation failed.';
        messages.value = messages.value.filter((m) => m !== assistantMessage);
        if (isNewPendingSession) history.value = history.value.filter((s) => s.id !== sessionId.value);
    } finally {
        sending.value = false;
        const entry = history.value.find((s) => s.id === sessionId.value);
        if (entry) entry.running = false;
        scrollToBottom();
    }
};

const startSession = (title) => {
    if (!sessionId.value) {
        sessionId.value = crypto.randomUUID();
        sessionOwnerId.value = currentUserId.value;
        isNewPendingSession = true;
    }
    error.value = '';
    upsertHistoryEntry({
        id: sessionId.value,
        title: (title || 'Image').slice(0, 60),
        updated_at: new Date().toISOString(),
        owner_id: currentUserId.value,
        running: true,
    });
};

const send = async () => {
    if (!canSend.value) return;
    if (mode.value === 'image') return generateImage();

    const content = input.value.trim();
    const images = pendingImages.value.map((img) => img.id);
    pendingImages.value.forEach((img) => URL.revokeObjectURL(img.preview));
    pendingImages.value = [];

    startSession(content || 'Image');
    messages.value.push({ role: 'user', content, ...(images.length ? { images } : {}) });
    input.value = '';
    sending.value = true;
    scrollToBottom();

    // Snapshot the payload before pushing the assistant placeholder — once
    // pushed, Vue wraps it in a reactive Proxy, so comparing this local
    // reference against array entries later (`m !== assistantMessage`)
    // would never match and silently include the empty placeholder.
    // Only user images are sent; generated ones stay as their text.
    const outgoingMessages = messages.value.map((m) => ({
        role: m.role,
        content: m.content,
        ...(m.role === 'user' && m.images?.length ? { images: m.images } : {}),
    }));

    messages.value.push({ role: 'assistant', content: '', usage: null, model: '', providerName: '', tools: [] });
    const assistantMessage = messages.value[messages.value.length - 1];

    abortController = new AbortController();
    stoppedByUser = false;
    let processedLength = 0;
    let sseBuffer = '';

    const handleStreamEvent = (eventName, payload) => {
        if (eventName === 'delta') {
            assistantMessage.content += payload.text;
            scrollToBottom(false);
        } else if (eventName === 'tool') {
            const step = assistantMessage.tools.find((t) => t.id === payload.id);
            if (step) {
                Object.assign(step, { status: payload.status, preview: payload.preview || '', duration_ms: payload.duration_ms });
            } else {
                assistantMessage.tools.push({ ...payload, preview: payload.preview || '', open: false });
            }
            scrollToBottom(false);
        } else if (eventName === 'done') {
            assistantMessage.content = payload.content;
            assistantMessage.model = payload.model;
            assistantMessage.providerName = payload.provider_name || '';
            assistantMessage.usage = { input: payload.input_tokens, output: payload.output_tokens };
            isNewPendingSession = false;
            persist();
        } else if (eventName === 'error') {
            error.value = payload.message || 'Request failed.';
            messages.value = messages.value.filter((m) => m !== assistantMessage);
            if (isNewPendingSession) {
                history.value = history.value.filter((s) => s.id !== sessionId.value);
            }
        }
    };

    const onDownloadProgress = (progressEvent) => {
        const fullText = progressEvent.event?.target?.responseText ?? progressEvent.target?.responseText ?? '';
        sseBuffer += fullText.slice(processedLength);
        processedLength = fullText.length;

        let boundary;
        while ((boundary = sseBuffer.indexOf('\n\n')) !== -1) {
            const rawEvent = sseBuffer.slice(0, boundary);
            sseBuffer = sseBuffer.slice(boundary + 2);

            let eventName = 'message';
            let dataLine = '';
            for (const line of rawEvent.split('\n')) {
                if (line.startsWith('event:')) eventName = line.slice(6).trim();
                if (line.startsWith('data:')) dataLine += line.slice(5).trim();
            }
            if (!dataLine) continue;

            handleStreamEvent(eventName, JSON.parse(dataLine));
        }
    };

    try {
        await axios.post(streamRoute(), {
            model: model.value === MODEL_AUTO ? null : model.value,
            messages: outgoingMessages,
            tools: toolsMode.value,
        }, {
            signal: abortController.signal,
            responseType: 'text',
            headers: { Accept: 'text/event-stream' },
            onDownloadProgress,
        });
    } catch (e) {
        const wasCancelled = axios.isCancel(e) || e.name === 'CanceledError' || e.code === 'ERR_CANCELED';
        if (!wasCancelled) {
            error.value = e.response?.data?.message || e.message || 'Request failed.';
        }
        if (!assistantMessage.content) {
            messages.value = messages.value.filter((m) => m !== assistantMessage);
        }
        if (stoppedByUser && assistantMessage.content) {
            // Stopped mid-answer: keep and save what arrived so far.
            isNewPendingSession = false;
            await persist();
        } else if (wasCancelled || (isNewPendingSession && !assistantMessage.content)) {
            history.value = history.value.filter((s) => s.id !== sessionId.value);
        } else if (assistantMessage.content) {
            await persist();
        }
    } finally {
        sending.value = false;
        abortController = null;
        stoppedByUser = false;
        const entry = history.value.find((s) => s.id === sessionId.value);
        if (entry) entry.running = false;
        scrollToBottom();
    }
};

const onKeydown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
    }
};

const fmtDate = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString();
};

onMounted(() => {
    model.value = MODEL_AUTO;
    loadHistory();
    document.addEventListener('keydown', onViewerKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onViewerKeydown);
    abortController?.abort();
});
</script>

<template>
    <Head title="AI Chat" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">Chat</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ isAuto ? 'Auto — picks a provider automatically, fails over on rate limits' : (provider?.driver_label || 'No provider selected') }}</p>
                </div>
                <Link v-if="provider" :href="panelRoute('ai-gateway.providers.edit', { provider: provider.id })" class="text-sm text-slate-500 hover:underline"><i class="bi bi-gear"></i> Provider settings</Link>
            </div>
        </template>

        <div v-if="!providers.length" class="mx-auto rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800">
            <i class="bi bi-robot mb-2 block text-3xl text-slate-300 dark:text-slate-600"></i>
            No active AI providers yet.
            <Link :href="panelRoute('ai-gateway.providers.create')" class="text-blue-600 hover:underline">Add one</Link>
            to start chatting.
        </div>

        <div v-else class="relative mx-auto flex h-[calc(100vh-11rem)] min-h-[32rem] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <!-- History sidebar -->
            <div v-if="sidebarOpen" class="absolute inset-0 z-10 bg-slate-900/30 md:hidden" @click="sidebarOpen = false"></div>
            <aside
                class="absolute inset-y-0 left-0 z-20 w-72 flex-shrink-0 flex-col border-r border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900 md:static md:flex md:w-64"
                :class="sidebarOpen ? 'flex' : 'hidden'"
            >
                <div class="space-y-2 p-3">
                    <button @click="newChat" class="flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                        <i class="bi bi-plus-lg"></i> New chat
                    </button>
                    <select v-model="providerId" @change="onProviderChange" class="w-full rounded-lg border-slate-300 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-800">
                        <option v-if="canAuto" :value="AUTO">Auto (all providers)</option>
                        <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <div class="relative">
                        <i class="bi bi-search pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input v-model="historySearch" type="search" placeholder="Search chats" class="w-full rounded-lg border-slate-300 py-1.5 pl-8 text-sm dark:border-slate-600 dark:bg-slate-800" />
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto px-2 pb-2">
                    <div v-if="historyLoading" class="px-2 py-4 text-center text-xs text-slate-400">Loading…</div>
                    <div v-else-if="!filteredHistory.length" class="px-2 py-4 text-center text-xs text-slate-400">{{ historySearch ? 'No matching chats.' : 'No saved chats yet.' }}</div>
                    <div
                        v-for="s in filteredHistory"
                        :key="s.id"
                        class="group mb-0.5 flex items-center rounded-lg"
                        :class="s.id === sessionId ? 'bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700' : 'hover:bg-slate-200/60 dark:hover:bg-slate-800'"
                    >
                        <button type="button" @click="openChat(s)" class="min-w-0 flex-1 px-3 py-2 text-left">
                            <div class="flex items-center gap-1.5">
                                <span v-if="s.running" class="inline-block h-1.5 w-1.5 flex-shrink-0 animate-pulse rounded-full bg-emerald-500" title="Generating…"></span>
                                <span class="truncate text-sm text-slate-700 dark:text-slate-200">{{ s.title }}</span>
                            </div>
                            <div class="truncate text-[10px] text-slate-400">
                                {{ fmtDate(s.updated_at) }}
                                <span v-if="s.owner_id !== currentUserId && s.owner_name"> · {{ s.owner_name }}</span>
                            </div>
                        </button>
                        <button type="button" @click="deleteChat(s, $event)" class="mr-1 shrink-0 rounded p-1.5 text-slate-400 opacity-0 hover:bg-slate-200 hover:text-red-500 group-hover:opacity-100 dark:hover:bg-slate-700" :title="s.running ? 'Cancel' : 'Delete'"><i class="bi bi-trash3 text-xs"></i></button>
                    </div>
                </div>
            </aside>

            <!-- Chat panel -->
            <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-3 py-2 dark:border-slate-700">
                    <button type="button" @click="sidebarOpen = true" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 md:hidden" title="Chats"><i class="bi bi-layout-sidebar"></i></button>
                    <select v-model="model" :disabled="readOnly || isAuto" :title="isAuto ? 'Provider is Auto, so model is Auto too' : ''" class="max-w-[16rem] rounded-lg border-slate-300 py-1 text-sm disabled:opacity-60 dark:border-slate-600 dark:bg-slate-900">
                        <option v-if="isAuto" :value="MODEL_AUTO">Auto (any provider, any model)</option>
                        <template v-else>
                            <option v-if="!availableModels.length" value="">{{ mode === 'image' ? 'No image models on this provider' : 'No models linked' }}</option>
                            <option v-else :value="MODEL_AUTO">Auto (any model on {{ provider?.name }})</option>
                            <option v-for="m in availableModels" :key="m.name" :value="m.name">{{ m.display_name || m.name }}</option>
                        </template>
                    </select>
                    <div v-if="hasImageModels && !readOnly" class="flex rounded-lg bg-slate-100 p-0.5 text-xs dark:bg-slate-900">
                        <button type="button" @click="setMode('chat')" :disabled="sending" class="rounded-md px-2.5 py-1" :class="mode === 'chat' ? 'bg-white text-blue-700 shadow-sm dark:bg-slate-700 dark:text-blue-300' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'"><i class="bi bi-chat-dots"></i> Chat</button>
                        <button type="button" @click="setMode('image')" :disabled="sending" class="rounded-md px-2.5 py-1" :class="mode === 'image' ? 'bg-white text-blue-700 shadow-sm dark:bg-slate-700 dark:text-blue-300' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'"><i class="bi bi-image"></i> Image</button>
                    </div>
                    <div class="ml-auto">
                        <span v-if="readOnly" class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-500 dark:bg-slate-900"><i class="bi bi-eye"></i> Viewing another user's chat (read-only)</span>
                        <label v-else-if="canUseServerTools && mode === 'chat'" class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400" title="Let the AI use the MCP tools on this server (logs, files, commands)">
                            <i class="bi bi-tools"></i>
                            <span class="hidden sm:inline">Server tools</span>
                            <select v-model="toolsMode" @change="onToolsModeChange" :disabled="sending" class="rounded-lg border-slate-300 py-1 text-sm dark:border-slate-600 dark:bg-slate-900" :class="toolsMode === 'full' ? 'border-amber-400 text-amber-700 dark:text-amber-400' : ''">
                                <option value="off">Off</option>
                                <option value="read">Read-only</option>
                                <option value="full">Full access</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div ref="scrollEl" class="flex-1 overflow-y-auto">
                    <div class="mx-auto max-w-3xl space-y-6 px-4 py-6">
                        <div v-if="!messages.length" class="flex flex-col items-center pt-12 text-center">
                            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-50 text-2xl text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                                <i class="bi" :class="mode === 'image' ? 'bi-image' : 'bi-stars'"></i>
                            </div>
                            <h2 class="text-base font-semibold text-slate-700 dark:text-slate-200">{{ mode === 'image' ? 'Generate an image' : 'How can I help?' }}</h2>
                            <p class="mt-1 text-sm text-slate-400">Send a message to test {{ isAuto ? 'your providers' : `"${provider?.name}"` }} live.</p>
                            <div v-if="!readOnly" class="mt-6 flex max-w-xl flex-wrap justify-center gap-2">
                                <button v-for="q in suggestions" :key="q" type="button" @click="useSuggestion(q)" class="rounded-full border border-slate-200 px-3 py-1.5 text-xs text-slate-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 dark:border-slate-600 dark:text-slate-300 dark:hover:border-blue-500/50 dark:hover:bg-blue-500/10 dark:hover:text-blue-300">{{ q }}</button>
                            </div>
                        </div>

                        <div v-for="(m, i) in messages" :key="i">
                            <!-- User message -->
                            <div v-if="m.role === 'user'" class="flex justify-end">
                                <div class="max-w-[85%]">
                                    <div v-if="m.images?.length" class="mb-1.5 flex flex-wrap justify-end gap-2">
                                        <button v-for="(id, k) in m.images" :key="id" type="button" @click="openViewer(m.images, k)" class="cursor-zoom-in">
                                            <img :src="mediaUrl(id)" alt="" class="h-24 w-24 rounded-xl object-cover ring-1 ring-slate-200 hover:opacity-90 dark:ring-slate-700" />
                                        </button>
                                    </div>
                                    <div v-if="m.content" class="whitespace-pre-wrap break-words rounded-2xl rounded-br-md bg-blue-600 px-4 py-2.5 text-sm text-white">{{ m.content }}</div>
                                </div>
                            </div>

                            <!-- Assistant message -->
                            <div v-else class="group flex gap-3">
                                <div class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-violet-500 text-xs text-white">
                                    <i class="bi bi-stars"></i>
                                </div>
                                <div class="min-w-0 flex-1 text-slate-800 dark:text-slate-100">
                                    <button v-if="m.tools?.length > 3 && !m.tools.some((t) => t.status === 'running')" type="button" @click="m.toolsOpen = !m.toolsOpen" class="mb-1.5 inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-500 hover:border-blue-300 hover:text-blue-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400 dark:hover:border-blue-500/50 dark:hover:text-blue-300">
                                        <i class="bi bi-tools"></i> {{ toolSummary(m.tools) }}
                                        <i class="bi" :class="m.toolsOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                    </button>
                                    <div v-if="m.tools?.length && !toolsFolded(m)" class="mb-3 space-y-1">
                                        <div v-for="step in m.tools" :key="step.id" class="rounded-lg border border-slate-200 bg-slate-50 text-xs dark:border-slate-700 dark:bg-slate-900/60">
                                            <button type="button" @click="step.open = !step.open" class="flex w-full items-center gap-2 px-2.5 py-1.5 text-left">
                                                <span v-if="step.status === 'running'" class="inline-block h-2 w-2 flex-shrink-0 animate-pulse rounded-full bg-blue-500"></span>
                                                <i v-else-if="step.status === 'ok'" class="bi bi-check-circle-fill text-emerald-500"></i>
                                                <i v-else class="bi bi-x-circle-fill text-red-500"></i>
                                                <i class="bi flex-shrink-0 text-slate-400" :class="toolIcon(step)"></i>
                                                <span class="min-w-0 flex-1 truncate" :title="step.name">{{ toolLabel(step) }}</span>
                                                <span v-if="step.duration_ms != null" class="shrink-0 text-slate-400">{{ (step.duration_ms / 1000).toFixed(1) }}s</span>
                                                <i class="bi text-slate-400" :class="step.open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                            </button>
                                            <pre v-if="step.open" class="max-h-48 overflow-auto whitespace-pre-wrap break-all border-t border-slate-200 px-2.5 py-1.5 font-mono text-[11px] dark:border-slate-700">{{ JSON.stringify(step.arguments, null, 2) }}{{ step.preview ? `\n→ ${step.preview}` : '' }}</pre>
                                        </div>
                                    </div>
                                    <div v-if="m.images?.length" class="mb-2 flex flex-wrap gap-2">
                                        <button v-for="(id, k) in m.images" :key="id" type="button" @click="openViewer(m.images, k)" class="cursor-zoom-in">
                                            <img :src="mediaUrl(id)" alt="" class="max-h-80 max-w-full rounded-xl object-cover ring-1 ring-slate-200 hover:opacity-90 dark:ring-slate-700" />
                                        </button>
                                    </div>
                                    <div v-if="m.generating" class="flex items-center gap-2 text-sm text-slate-500"><span class="inline-block h-2 w-2 animate-pulse rounded-full bg-blue-500"></span> Generating image…</div>
                                    <div v-else-if="sending && i === messages.length - 1 && !m.content && !m.tools?.some((t) => t.status === 'running')" class="flex h-6 items-center gap-1">
                                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400 [animation-delay:-0.3s]"></span>
                                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400 [animation-delay:-0.15s]"></span>
                                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400"></span>
                                    </div>
                                    <MarkdownContent v-if="m.content" :content="m.content" />
                                    <span v-if="sending && i === messages.length - 1 && m.content && !m.generating" class="ml-0.5 inline-block h-4 w-1.5 animate-pulse bg-slate-400 align-middle"></span>
                                    <div v-if="m.content && !(sending && i === messages.length - 1)" class="mt-2 flex items-center gap-3 text-[11px] text-slate-400">
                                        <button type="button" @click="copyMessage(m, i)" class="rounded px-1 py-0.5 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-200" title="Copy">
                                            <i class="bi" :class="copiedIndex === i ? 'bi-check2' : 'bi-clipboard'"></i> {{ copiedIndex === i ? 'Copied' : 'Copy' }}
                                        </button>
                                        <span v-if="m.model" class="opacity-0 transition-opacity group-hover:opacity-100">
                                            {{ m.model }}<span v-if="m.providerName"> via {{ m.providerName }}</span><template v-if="m.usage"> · {{ m.usage.input }}→{{ m.usage.output }} tokens</template>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="error" class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                            <i class="bi bi-exclamation-triangle"></i><span>{{ error }}</span>
                        </div>
                    </div>
                </div>

                <div class="px-4 pb-4 pt-2" @dragover.prevent @drop.prevent="onDrop">
                    <div class="mx-auto max-w-3xl">
                        <p v-if="imagesWillBeOcr" class="mb-2 text-xs text-amber-600">This model can't see images (no "vision" capability), so the text in them will be read with OCR and sent instead.</p>
                        <div class="rounded-2xl border border-slate-300 bg-white shadow-sm focus-within:border-blue-400 focus-within:ring-2 focus-within:ring-blue-100 dark:border-slate-600 dark:bg-slate-900 dark:focus-within:ring-blue-500/20" :class="readOnly ? 'opacity-60' : ''">
                            <div v-if="pendingImages.length" class="flex flex-wrap gap-2 px-3 pt-3">
                                <div v-for="(img, k) in pendingImages" :key="k" class="relative">
                                    <img :src="img.preview" alt="" class="h-16 w-16 rounded-lg object-cover" :class="img.uploading ? 'opacity-50' : ''" />
                                    <span v-if="img.uploading" class="absolute inset-0 flex items-center justify-center"><span class="h-4 w-4 animate-spin rounded-full border-2 border-blue-500 border-t-transparent"></span></span>
                                    <button type="button" @click="removePending(img)" class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-slate-800 text-[10px] text-white" title="Remove"><i class="bi bi-x"></i></button>
                                </div>
                            </div>
                            <textarea
                                ref="inputEl"
                                v-model="input"
                                @keydown="onKeydown"
                                @paste="onPaste"
                                :disabled="readOnly"
                                rows="1"
                                :placeholder="mode === 'image' ? 'Describe the image to generate…' : 'Message…'"
                                class="block max-h-[200px] w-full resize-none border-0 bg-transparent px-4 pb-1 pt-3 text-sm focus:ring-0 disabled:cursor-not-allowed"
                            ></textarea>
                            <div class="flex items-center gap-1 px-2 pb-2">
                                <template v-if="mode === 'chat'">
                                    <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple class="hidden" @change="onPickFiles" />
                                    <button type="button" @click="fileInput?.click()" :disabled="readOnly || sending" title="Attach images (or paste / drop them)" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-800"><i class="bi bi-paperclip"></i></button>
                                </template>
                                <span class="hidden text-[11px] text-slate-400 sm:inline">{{ mode === 'image' ? 'Enter to generate' : 'Enter to send · Shift+Enter for newline' }}</span>
                                <button
                                    v-if="sending && mode === 'chat'"
                                    type="button"
                                    @click="stop"
                                    class="ml-auto flex h-8 items-center gap-1.5 rounded-lg bg-slate-800 px-3 text-xs font-medium text-white hover:bg-slate-700 dark:bg-slate-200 dark:text-slate-900 dark:hover:bg-white"
                                    title="Stop generating"
                                ><i class="bi bi-stop-fill"></i> Stop</button>
                                <button
                                    v-else
                                    type="button"
                                    @click="send"
                                    :disabled="!canSend"
                                    class="ml-auto flex h-8 items-center gap-1.5 rounded-lg bg-blue-600 px-3 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-40"
                                ><i class="bi" :class="mode === 'image' ? 'bi-magic' : 'bi-send-fill'"></i> {{ mode === 'image' ? 'Generate' : 'Send' }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Offcanvas
            :show="viewer.show"
            title="Image"
            :subtitle="viewer.images.length > 1 ? `${viewer.index + 1} of ${viewer.images.length}` : ''"
            width="wide"
            @close="viewer.show = false"
        >
            <div class="flex h-full flex-col gap-3">
                <div class="relative flex flex-1 items-center justify-center rounded-xl bg-slate-100 p-2 dark:bg-slate-800">
                    <img v-if="viewerUrl" :src="viewerUrl" alt="" class="max-h-[calc(100vh-12rem)] max-w-full rounded-lg object-contain" />
                    <template v-if="viewer.images.length > 1">
                        <button type="button" @click="stepViewer(-1)" class="absolute left-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-slate-700 shadow hover:bg-white dark:bg-slate-900/90 dark:text-slate-200" title="Previous"><i class="bi bi-chevron-left"></i></button>
                        <button type="button" @click="stepViewer(1)" class="absolute right-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-slate-700 shadow hover:bg-white dark:bg-slate-900/90 dark:text-slate-200" title="Next"><i class="bi bi-chevron-right"></i></button>
                    </template>
                </div>
                <div class="flex justify-end gap-2 text-sm">
                    <a :href="viewerUrl" download class="rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-800"><i class="bi bi-download"></i> Download</a>
                </div>
            </div>
        </Offcanvas>
    </AuthenticatedLayout>
</template>
