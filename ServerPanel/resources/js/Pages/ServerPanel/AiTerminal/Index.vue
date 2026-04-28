<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    activeSession: { type: Object, default: null },
    servers: { type: Array, default: () => [] },
});

const messageForm = useForm({
    message: '',
    server_id: props.servers?.[0]?.id || '',
});

const sending = ref(false);
const liveItems = ref([]);
const chatBodyRef = ref(null);
const activeStream = ref(null);
const currentPath = ref('/root');
const commandHistory = ref([]);
const historyIndex = ref(-1);
const filePath = ref('.env');
const fileContent = ref('');
const fileLoading = ref(false);
const fileSaving = ref(false);
const fileStatus = ref('');

const sendMessage = () => {
    if (!props.activeSession?.id || !messageForm.message.trim()) return;
    sending.value = true;
    const userText = messageForm.message;
    const trimmed = userText.trim();

    if (trimmed) {
        const last = commandHistory.value[commandHistory.value.length - 1];
        if (last !== trimmed) {
            commandHistory.value.push(trimmed);
        }
        historyIndex.value = commandHistory.value.length;
    }

    if (activeStream.value) {
        activeStream.value.close();
        activeStream.value = null;
    }

    liveItems.value.push(
        { role: 'user', source: 'USER', message: userText, created_at: new Date().toISOString() },
        { role: 'ai', source: 'SSH', message: '', created_at: new Date().toISOString() },
    );

    const params = new URLSearchParams({
        message: userText,
        server_id: String(messageForm.server_id || ''),
    });
    const url = `${route('ai-terminal.stream')}?${params.toString()}`;
    const source = new EventSource(url, { withCredentials: true });
    activeStream.value = source;

    source.addEventListener('line', (event) => {
        const payload = JSON.parse(event.data || '{}');
        const last = liveItems.value[liveItems.value.length - 1];
        if (last && last.role === 'ai') {
            last.message = [last.message, payload.line || ''].filter(Boolean).join('\n');
        }
        scrollToBottom();
    });

    source.addEventListener('cwd_update', (event) => {
        const payload = JSON.parse(event.data || '{}');
        if (payload.cwd) {
            currentPath.value = payload.cwd;
        }
    });

    source.addEventListener('done', (event) => {
        const payload = JSON.parse(event.data || '{}');
        if (payload.cwd) {
            currentPath.value = payload.cwd;
        }
        const last = liveItems.value[liveItems.value.length - 1];
        if (last && last.role === 'ai') {
            if (!last.message || !last.message.trim()) {
                last.message = payload.message || '';
            }
            last.source = payload.source || 'AI';
        } else {
            liveItems.value.push({ role: 'ai', source: payload.source || 'AI', message: payload.message || '', created_at: new Date().toISOString() });
        }
        source.close();
        activeStream.value = null;
        sending.value = false;
        messageForm.message = '';
        scrollToBottom();
    });

    source.addEventListener('error', (event) => {
        const payload = event?.data ? JSON.parse(event.data) : {};
        liveItems.value.push({
            role: 'ai',
            source: 'AI',
            message: payload.message || 'Streaming error.',
            created_at: new Date().toISOString(),
        });
        source.close();
        activeStream.value = null;
        sending.value = false;
    });
};

const onInputKeydown = (event) => {
    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
    if (!messageForm.message || messageForm.message.includes('\n')) return;
    if (!commandHistory.value.length) return;

    event.preventDefault();

    if (event.key === 'ArrowUp') {
        historyIndex.value = Math.max(0, historyIndex.value - 1);
        messageForm.message = commandHistory.value[historyIndex.value] || '';
        return;
    }

    historyIndex.value = Math.min(commandHistory.value.length, historyIndex.value + 1);
    if (historyIndex.value === commandHistory.value.length) {
        messageForm.message = '';
        return;
    }
    messageForm.message = commandHistory.value[historyIndex.value] || '';
};

const closeSession = () => {
    if (!props.activeSession?.id) return;
    window.axios.post(route('ai-terminal.close'), {}, { headers: { Accept: 'application/json' } })
        .finally(() => window.location.reload());
};

const readFile = async () => {
    if (!messageForm.server_id || !filePath.value.trim()) return;
    fileLoading.value = true;
    fileStatus.value = '';
    try {
        const { data } = await window.axios.post(route('ai-terminal.file.read'), {
            server_id: messageForm.server_id,
            path: filePath.value.trim(),
        });
        if (data?.ok) {
            fileContent.value = data.content || '';
            if (data.cwd) currentPath.value = data.cwd;
            fileStatus.value = `Loaded: ${data.path || filePath.value}`;
        } else {
            fileStatus.value = data?.message || 'Load failed.';
        }
    } catch (e) {
        fileStatus.value = e?.response?.data?.message || 'Load failed.';
    } finally {
        fileLoading.value = false;
    }
};

const saveFile = async () => {
    if (!messageForm.server_id || !filePath.value.trim()) return;
    fileSaving.value = true;
    fileStatus.value = '';
    try {
        const { data } = await window.axios.post(route('ai-terminal.file.save'), {
            server_id: messageForm.server_id,
            path: filePath.value.trim(),
            content: fileContent.value,
        });
        if (data?.ok) {
            if (data.cwd) currentPath.value = data.cwd;
            fileStatus.value = `Saved: ${data.path || filePath.value}`;
        } else {
            fileStatus.value = data?.message || 'Save failed.';
        }
    } catch (e) {
        fileStatus.value = e?.response?.data?.message || 'Save failed.';
    } finally {
        fileSaving.value = false;
    }
};

const messageItems = computed(() => {
    const base = (props.activeSession?.messages || []).map((m) => ({
        role: m.role,
        source: String(m.source || '').toUpperCase(),
        message: m.message,
        created_at: m.created_at,
    }));
    return [...base, ...liveItems.value];
});

const scrollToBottom = async () => {
    await nextTick();
    const body = chatBodyRef.value;
    if (!body) return;
    body.scrollTop = body.scrollHeight;
};

watch(
    () => messageItems.value.length,
    () => {
        scrollToBottom();
    },
    { immediate: true },
);

watch(
    () => messageForm.server_id,
    () => {
        currentPath.value = '/root';
    },
);
</script>

<template>
    <Head title="AI Terminal" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-lg font-semibold">AI Terminal Engine</h1></template>
        <div class="grid gap-4">
            <section class="flex min-h-[80vh] flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                    <h2 class="font-semibold">{{ activeSession?.title || 'AI Terminal' }}</h2>
                    <div class="flex items-center gap-2">
                        <span class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">Path: {{ currentPath }}</span>
                        <button v-if="activeSession?.id" class="rounded bg-slate-800 px-3 py-1 text-xs text-white dark:bg-slate-200 dark:text-slate-900" @click="closeSession">Close + Summary</button>
                    </div>
                </div>
                <div ref="chatBodyRef" class="max-h-[62vh] flex-1 space-y-3 overflow-y-auto bg-slate-50 p-4 dark:bg-slate-950/40">
                    <article
                        v-for="(item, idx) in messageItems"
                        :key="`${item.created_at}-${idx}`"
                        class="max-w-[85%] rounded-2xl p-3 text-sm shadow-sm"
                        :class="item.role === 'user' ? 'ml-auto bg-cyan-600 text-white' : 'bg-white text-slate-900 dark:bg-slate-800 dark:text-slate-100'"
                    >
                        <div class="mb-1 flex items-center justify-between gap-3 text-[11px] opacity-80">
                            <span class="font-semibold uppercase">{{ item.role }}</span>
                            <span
                                class="rounded px-2 py-0.5"
                                :class="item.role === 'user' ? 'bg-cyan-500/70 text-white' : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200'"
                            >{{ item.source }}</span>
                        </div>
                        <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ item.message }}</pre>
                    </article>
                </div>
                <form class="space-y-2 border-t border-slate-200 p-4 dark:border-slate-800" @submit.prevent="sendMessage">
                    <select v-model="messageForm.server_id" class="w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">No SSH server</option>
                        <option v-for="s in servers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <textarea
                        v-model="messageForm.message"
                        rows="3"
                        class="w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        placeholder="Type message or command..."
                        @keydown.enter.exact.prevent="sendMessage"
                        @keydown="onInputKeydown"
                    />
                    <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-200">
                        Current Path: <span class="font-mono">{{ currentPath }}</span>
                    </div>
                    <button class="w-full rounded bg-emerald-600 px-3 py-2 text-sm font-semibold text-white" :disabled="sending">{{ sending ? 'Sending...' : 'Send' }}</button>
                </form>
                <section class="space-y-2 border-t border-slate-200 p-4 dark:border-slate-800">
                    <div class="text-sm font-semibold">File Editor</div>
                    <div class="flex gap-2">
                        <input
                            v-model="filePath"
                            type="text"
                            class="w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                            placeholder="File path (e.g. .env or config/app.php)"
                        />
                        <button type="button" class="rounded bg-slate-700 px-3 py-2 text-xs font-semibold text-white" :disabled="fileLoading || !messageForm.server_id" @click="readFile">
                            {{ fileLoading ? 'Loading...' : 'Load' }}
                        </button>
                        <button type="button" class="rounded bg-emerald-700 px-3 py-2 text-xs font-semibold text-white" :disabled="fileSaving || !messageForm.server_id" @click="saveFile">
                            {{ fileSaving ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                    <textarea
                        v-model="fileContent"
                        rows="10"
                        class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"
                        placeholder="File content..."
                    />
                    <div class="text-xs text-slate-600 dark:text-slate-300">{{ fileStatus }}</div>
                </section>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
