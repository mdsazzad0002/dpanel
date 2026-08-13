<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
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
});

const providerId = ref(props.initialProviderId ?? props.providers[0]?.id ?? null);
const isAuto = computed(() => providerId.value === AUTO);
const provider = computed(() => (isAuto.value ? null : props.providers.find((p) => p.id === providerId.value) ?? null));

const canAuto = computed(() => props.providers.length > 1);

const availableModels = computed(() => {
    if (!isAuto.value) return provider.value?.models || [];

    const seen = new Map();
    for (const p of props.providers) {
        for (const m of p.models || []) {
            if (!seen.has(m.name)) seen.set(m.name, m);
        }
    }
    return [...seen.values()];
});

const model = ref('');
const sessionId = ref(null);
const sessionOwnerId = ref(null);
const messages = ref([]);
const input = ref('');
const sending = ref(false);
const error = ref('');
const scrollEl = ref(null);
let abortController = null;
let isNewPendingSession = false;

const history = ref([]);
const historyLoading = ref(true);

const readOnly = computed(() => sessionId.value && sessionOwnerId.value && currentUserId.value && sessionOwnerId.value !== currentUserId.value);

const scrollToBottom = () => {
    nextTick(() => {
        if (scrollEl.value) scrollEl.value.scrollTop = scrollEl.value.scrollHeight;
    });
};

const canSend = computed(() => input.value.trim().length > 0 && !sending.value && (isAuto.value || !!provider.value) && !readOnly.value);

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
            messages: messages.value.map((m) => ({ role: m.role, content: m.content })),
        });
        sessionId.value = data.id;
        sessionOwnerId.value = data.owner_id ?? currentUserId.value;
        upsertHistoryEntry({ ...data, running: false });
    } catch (e) {
        // Non-fatal — the conversation is still usable even if saving history fails.
    }
};

const send = async () => {
    const content = input.value.trim();
    if (!content || sending.value || (!isAuto.value && !provider.value) || readOnly.value) return;

    if (!sessionId.value) {
        sessionId.value = crypto.randomUUID();
        sessionOwnerId.value = currentUserId.value;
        isNewPendingSession = true;
    }

    error.value = '';
    messages.value.push({ role: 'user', content });
    input.value = '';
    sending.value = true;
    scrollToBottom();

    upsertHistoryEntry({
        id: sessionId.value,
        title: content.slice(0, 60),
        updated_at: new Date().toISOString(),
        owner_id: currentUserId.value,
        running: true,
    });

    // Snapshot the payload before pushing the assistant placeholder — once
    // pushed, Vue wraps it in a reactive Proxy, so comparing this local
    // reference against array entries later (`m !== assistantMessage`)
    // would never match and silently include the empty placeholder.
    const outgoingMessages = messages.value.map((m) => ({ role: m.role, content: m.content }));

    messages.value.push({ role: 'assistant', content: '', usage: null, model: '', providerName: '' });
    const assistantMessage = messages.value[messages.value.length - 1];

    abortController = new AbortController();
    let processedLength = 0;
    let sseBuffer = '';

    const handleStreamEvent = (eventName, payload) => {
        if (eventName === 'delta') {
            assistantMessage.content += payload.text;
            scrollToBottom();
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
        if (wasCancelled || (isNewPendingSession && !assistantMessage.content)) {
            history.value = history.value.filter((s) => s.id !== sessionId.value);
        } else if (assistantMessage.content) {
            await persist();
        }
    } finally {
        sending.value = false;
        abortController = null;
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
});

onBeforeUnmount(() => {
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
                <Link v-if="provider" :href="panelRoute('ai-gateway.providers.edit', { provider: provider.id })" class="text-sm text-slate-500 hover:underline">Provider settings</Link>
            </div>
        </template>

        <div v-if="!providers.length" class="mx-auto rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800">
            No active AI providers yet.
            <Link :href="panelRoute('ai-gateway.providers.create')" class="text-blue-600 hover:underline">Add one</Link>
            to start chatting.
        </div>

        <div v-else class="mx-auto flex h-[75vh] overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
            <!-- History sidebar -->
            <div class="flex w-64 flex-shrink-0 flex-col border-r border-slate-200 dark:border-slate-700">
                <div class="space-y-2 border-b border-slate-200 p-2 dark:border-slate-700">
                    <select v-model="providerId" @change="onProviderChange" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option v-if="canAuto" :value="AUTO">Auto (all providers)</option>
                        <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <button @click="newChat" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">
                        + New chat
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-2">
                    <div v-if="historyLoading" class="px-2 py-4 text-center text-xs text-slate-400">Loading…</div>
                    <div v-else-if="!history.length" class="px-2 py-4 text-center text-xs text-slate-400">No saved chats yet.</div>
                    <div v-for="s in history" :key="s.id" class="group mb-1 flex items-start gap-1">
                        <button
                            type="button"
                            @click="openChat(s)"
                            class="min-w-0 flex-1 rounded-md px-2 py-2 text-left text-xs text-blue-700 hover:bg-slate-100 hover:underline dark:text-blue-400 dark:hover:bg-slate-700"
                            :class="s.id === sessionId ? 'bg-slate-100 underline dark:bg-slate-700' : ''"
                        >
                            <div class="flex items-center gap-1">
                                <span v-if="s.running" class="inline-block h-1.5 w-1.5 flex-shrink-0 animate-pulse rounded-full bg-emerald-500" title="Generating…"></span>
                                <span class="truncate font-medium">{{ s.title }}</span>
                            </div>
                            <div class="text-[10px] font-normal text-slate-400">
                                {{ fmtDate(s.updated_at) }}
                                <span v-if="s.owner_id !== currentUserId && s.owner_name"> · {{ s.owner_name }}</span>
                            </div>
                        </button>
                        <button type="button" @click="deleteChat(s, $event)" class="hidden shrink-0 self-start px-1 py-2 text-slate-400 hover:text-red-500 group-hover:block" :title="s.running ? 'Cancel' : 'Delete'">✕</button>
                    </div>
                </div>
            </div>

            <!-- Chat panel -->
            <div class="flex flex-1 flex-col overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-2 dark:border-slate-700">
                    <select v-model="model" :disabled="readOnly || isAuto" :title="isAuto ? 'Provider is Auto, so model is Auto too' : ''" class="rounded-md border border-slate-300 px-2 py-1 text-sm disabled:opacity-60 dark:border-slate-600 dark:bg-slate-900">
                        <option v-if="isAuto" :value="MODEL_AUTO">Auto (any provider, any model)</option>
                        <template v-else>
                            <option v-if="!availableModels.length" value="">No models linked</option>
                            <option v-else :value="MODEL_AUTO">Auto (any model on {{ provider?.name }})</option>
                            <option v-for="m in availableModels" :key="m.name" :value="m.name">{{ m.display_name || m.name }}</option>
                        </template>
                    </select>
                    <span v-if="readOnly" class="text-xs text-slate-400">Viewing another user's chat (read-only)</span>
                </div>

                <div ref="scrollEl" class="flex-1 space-y-4 overflow-y-auto px-4 py-4">
                    <div v-if="!messages.length" class="mt-10 text-center text-sm text-slate-400">
                        Send a message to test {{ isAuto ? 'your providers' : `"${provider?.name}"` }} live.
                    </div>

                    <div v-for="(m, i) in messages" :key="i" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                        <div
                            class="max-w-[80%] whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm"
                            :class="m.role === 'user'
                                ? 'bg-blue-600 text-white'
                                : 'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-100'"
                        >
                            {{ m.content }}<span v-if="sending && i === messages.length - 1 && m.role === 'assistant'" class="animate-pulse">▍</span>
                            <div v-if="m.role === 'assistant' && m.usage" class="mt-1 text-[10px] opacity-60">
                                {{ m.model }}<span v-if="m.providerName"> via {{ m.providerName }}</span> · {{ m.usage.input }}→{{ m.usage.output }} tokens
                            </div>
                        </div>
                    </div>

                    <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ error }}</div>
                </div>

                <div class="border-t border-slate-200 p-3 dark:border-slate-700">
                    <div class="flex items-end gap-2">
                        <textarea
                            v-model="input"
                            @keydown="onKeydown"
                            :disabled="readOnly"
                            rows="1"
                            placeholder="Message… (Enter to send, Shift+Enter for newline)"
                            class="max-h-40 min-h-[2.5rem] flex-1 resize-none rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-600 dark:bg-slate-900"
                        ></textarea>
                        <button
                            @click="send"
                            :disabled="!canSend"
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            Send
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
