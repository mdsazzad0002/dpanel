<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    jobs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    servers: { type: Array, default: () => [] },
    selectedCommand: { type: Object, default: null },
    upgradeLogs: { type: Array, default: () => [] },
    markdownHistory: { type: Array, default: () => [] },
    canApprove: { type: Boolean, default: false },
});

const filterForm = useForm({
    status: props.filters.status || '',
    risk: props.filters.risk || '',
    server_id: props.filters.server_id || '',
    q: props.filters.q || '',
    selected: props.selectedCommand?.id || '',
});

const commandForm = useForm({
    server_id: props.filters.server_id || props.selectedCommand?.server_id || props.servers?.[0]?.id || '',
    command: '',
    auto_fix: false,
    tags: [],
});
const adminUserForm = useForm({
    username: '',
    panel_email: '',
    panel_password: '',
    shell: '/bin/bash',
    disable_root: true,
});
const sending = ref(false);
const requestError = ref('');
const transientChatItems = ref([]);
const chatFeedRef = ref(null);
const liveSelectedCommand = ref(props.selectedCommand || null);
const liveInterval = ref(null);

const shellQuote = (value) => '"' + String(value).replace(/(["\\$`])/g, '\\$1') + '"';

const buildAdminUserCommand = () => {
    const username = String(adminUserForm.username || '').trim();
    const email = String(adminUserForm.panel_email || '').trim();
    const password = String(adminUserForm.panel_password || '');
    const shell = String(adminUserForm.shell || '/bin/bash').trim() || '/bin/bash';

    if (!username) {
        requestError.value = 'Username is required to build the admin user command.';
        return;
    }

    if (!password) {
        requestError.value = 'Panel password is required to build the admin user command.';
        return;
    }

    requestError.value = '';

    const parts = ['panel', 'user:create', '--username', shellQuote(username)];
    parts.push('--panel-password', shellQuote(password));

    if (email) {
        parts.push('--panel-email', shellQuote(email));
    }

    if (shell) {
        parts.push('--shell', shellQuote(shell));
    }

    if (adminUserForm.disable_root) {
        parts.push('--disable-root');
    } else {
        parts.push('--keep-root');
    }

    commandForm.command = parts.join(' ');
};

const applyFilters = () => {
    router.get(route('commands.index'), filterForm.data(), { preserveState: true, preserveScroll: true });
};

const openCommand = (jobId) => {
    router.get(route('commands.index'), {
        ...filterForm.data(),
        selected: jobId,
    }, { preserveState: true, preserveScroll: true });
};

const deleteCommand = (jobId) => {
    if (!confirm('Delete this command log?')) return;

    router.delete(route('commands.destroy', jobId), {
        data: {
            server_id: filterForm.server_id || '',
            status: filterForm.status || '',
            risk: filterForm.risk || '',
            q: filterForm.q || '',
            selected: filterForm.selected || '',
        },
        preserveScroll: true,
        preserveState: true,
    });
};

const newChat = () => {
    commandForm.command = '';
    router.get(route('commands.index'), {
        ...filterForm.data(),
        selected: '',
    }, { preserveState: true, preserveScroll: true });
};

const runNewCommand = () => {
    if (!commandForm.command.trim() || !commandForm.server_id) return;
    sending.value = true;
    requestError.value = '';

    window.axios.post(
        route('commands.store'),
        {
            server_id: commandForm.server_id,
            command: commandForm.command,
            auto_fix: commandForm.auto_fix,
            parent_id: props.selectedCommand?.id || null,
            tags: commandForm.tags,
        },
        {
            headers: {
                Accept: 'application/json',
            },
        },
    ).then((response) => {
        if (response?.data?.chat_mode) {
            transientChatItems.value.push(
                { id: `chat-user-${Date.now()}`, role: 'user', title: 'You', body: commandForm.command, meta: new Date().toISOString() },
                { id: `chat-ai-${Date.now()}-a`, role: 'assistant', title: 'AI Assistant', body: String(response?.data?.message || ''), meta: new Date().toISOString() },
                { id: `chat-ai-${Date.now()}-s`, role: 'system', title: 'Server', body: String(response?.data?.system_info || ''), meta: new Date().toISOString() },
            );
            commandForm.command = '';
            return;
        }

        const job = response?.data?.job || null;
        const jobId = job?.id;
        const activeThreadId = props.selectedCommand?.id || jobId || '';
        liveSelectedCommand.value = job;
        filterForm.selected = activeThreadId;
        startLivePolling();
        router.get(route('commands.index'), {
            ...filterForm.data(),
            server_id: commandForm.server_id,
            selected: activeThreadId,
        }, { preserveState: true, preserveScroll: true, only: ['jobs', 'selectedCommand', 'upgradeLogs'] });
        commandForm.command = '';
    }).catch((error) => {
        requestError.value = String(error?.response?.data?.message || error?.response?.data?.error || 'Command failed.');
        router.reload({ only: ['jobs', 'selectedCommand'] });
    }).finally(() => {
        sending.value = false;
    });
};

const selectedCommandRef = computed(() => liveSelectedCommand.value || props.selectedCommand);

const chatItems = computed(() => {
    const transient = [...transientChatItems.value];
    const job = selectedCommandRef.value;
    if (!job) return transient;
    const jobs = [job, ...(job.children || [])]
        .filter(Boolean)
        .sort((a, b) => new Date(a.created_at || 0).getTime() - new Date(b.created_at || 0).getTime());

    const items = [];
    jobs.forEach((entry) => {
        items.push({ id: `user-${entry.id}`, role: 'user', title: 'You', body: entry.command || '(empty)', meta: entry.created_at });
        if (entry.output || entry.error_output) {
            const output = [];
            if (entry.output) output.push(`STDOUT\n${entry.output}`);
            if (entry.error_output) output.push(`STDERR\n${entry.error_output}`);
            output.push(`Exit: ${entry.exit_code ?? '-'}`);
            items.push({ id: `system-${entry.id}`, role: 'system', title: 'Server', body: output.join('\n\n'), meta: entry.finished_at || entry.updated_at });
        }
        if (entry.ai_summary || entry.ai_fix_suggestion) {
            items.push({
                id: `assistant-${entry.id}`,
                role: 'assistant',
                title: 'AI Assistant',
                body: [
                    entry.ai_summary ? `Summary:\n${entry.ai_summary}` : null,
                    entry.ai_fix_suggestion ? `Suggested Fix:\n${entry.ai_fix_suggestion}` : null,
                ].filter(Boolean).join('\n\n'),
                meta: entry.updated_at,
            });
        }
        (entry.events || []).forEach((event) => {
            items.push({ id: `event-${entry.id}-${event.id}`, role: 'event', title: `Event: ${event.type}`, body: event.message, meta: event.created_at });
        });
    });

    return [...items, ...transient].sort((a, b) => new Date(a.meta || 0).getTime() - new Date(b.meta || 0).getTime());
});

const stepStatuses = computed(() => {
    const job = selectedCommandRef.value;
    if (!job) return [];
    const map = new Map();
    const allEvents = [...(job.events || [])];
    (job.children || []).forEach((child) => {
        (child.events || []).forEach((event) => allEvents.push(event));
    });

    allEvents
        .filter((event) => event.type === 'step_status' || (event.type === 'output' && event.meta?.stream_type === 'step_status'))
        .forEach((event) => {
            const meta = event.meta || {};
            const key = Number(meta.step_no || 0);
            if (!key) return;
            map.set(key, {
                step_no: key,
                label: String(meta.label || `Step ${key}`),
                status: String(meta.status || 'pending'),
            });
        });

    return [...map.values()].sort((a, b) => a.step_no - b.step_no);
});

const stopLivePolling = () => {
    if (liveInterval.value) {
        clearInterval(liveInterval.value);
        liveInterval.value = null;
    }
};

const fetchLiveSelectedCommand = () => {
    const selectedId = selectedCommandRef.value?.id;
    if (!selectedId) return;

    window.axios.get(route('commands.live', selectedId), {
        headers: { Accept: 'application/json' },
    }).then((response) => {
        const job = response?.data?.job || null;
        if (!job) return;
        liveSelectedCommand.value = job;
        if (!['running', 'queued'].includes(String(job.status || ''))) {
            stopLivePolling();
            router.reload({ only: ['jobs', 'upgradeLogs'] });
        }
    }).catch(() => {
        stopLivePolling();
    });
};

const startLivePolling = () => {
    stopLivePolling();
    const status = String(selectedCommandRef.value?.status || '');
    if (!['running', 'queued'].includes(status)) return;
    liveInterval.value = setInterval(fetchLiveSelectedCommand, 1000);
};

const closeChat = () => {
    if (!selectedCommandRef.value?.id) return;
    router.post(route('commands.close', selectedCommandRef.value.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            router.reload({ only: ['jobs', 'selectedCommand', 'markdownHistory'] });
        },
    });
};

watch(chatItems, async () => {
    await nextTick();
    if (chatFeedRef.value) {
        chatFeedRef.value.scrollTop = chatFeedRef.value.scrollHeight;
    }
}, { deep: true });

watch(() => props.selectedCommand, (value) => {
    liveSelectedCommand.value = value || null;
    filterForm.selected = value?.id || '';
    startLivePolling();
}, { immediate: true });

onBeforeUnmount(() => {
    stopLivePolling();
});
</script>

<template>
    <Head title="Terminal Chat Commands" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-lg font-semibold">Terminal Command Chat</h1></template>

        <div class="grid gap-4 xl:grid-cols-[380px_1fr]">
            <section class="flex min-h-[82vh] max-h-[82vh] overflow-auto flex-col rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Recent Commands</h2>
                    <button class="rounded-md bg-cyan-700 px-3 py-1 text-xs font-semibold text-white" @click="newChat">New Chat</button>
                </div>

                <form class="space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700" @submit.prevent="applyFilters">
                    <input v-model="filterForm.q" type="text" class="w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" placeholder="Search command" />
                    <select v-model="filterForm.status" class="w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"><option value="">All Status</option><option>pending_approval</option><option>queued</option><option>running</option><option>success</option><option>failed</option><option>blocked</option></select>
                    <select v-model="filterForm.risk" class="w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"><option value="">All Risk</option><option>safe</option><option>approval_required</option><option>blocked</option></select>
                    <select v-model="filterForm.server_id" class="w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"><option value="">All Servers</option><option v-for="server in servers" :key="server.id" :value="server.id">{{ server.name }}</option></select>
                    <button class="w-full rounded bg-cyan-700 px-3 py-2 text-sm font-semibold text-white">Filter</button>
                </form>

                <div class="mt-3 flex-1 space-y-3 overflow-y-auto pr-1">
                    <div class="rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Markdown History</p>
                        <div class="mt-2 space-y-2">
                            <article v-for="md in markdownHistory" :key="md.path" class="rounded border border-slate-200 bg-slate-50 p-2 text-xs dark:border-slate-700 dark:bg-slate-800/60">
                                <p class="font-mono">{{ md.title }}</p>
                                <p class="mt-1 text-slate-600 dark:text-slate-300">{{ md.user_input || '-' }}</p>
                                <p v-if="md.ai_summary" class="mt-1 text-emerald-700 dark:text-emerald-300">{{ md.ai_summary }}</p>
                            </article>
                            <p v-if="markdownHistory.length === 0" class="text-xs text-slate-500 dark:text-slate-400">No markdown history yet.</p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">System Upgrade Logs</p>
                        <div class="mt-2 space-y-2">
                            <article v-for="log in upgradeLogs" :key="`upgrade-${log.id}`" class="rounded border border-amber-200 bg-amber-50 p-2 text-xs dark:border-amber-900/40 dark:bg-amber-900/20">
                                <p class="font-mono">{{ log.command }}</p>
                                <p class="mt-1 text-slate-600 dark:text-slate-300">{{ log.server?.name }} | {{ log.status }}</p>
                            </article>
                            <p v-if="upgradeLogs.length === 0" class="text-xs text-slate-500 dark:text-slate-400">No upgrade logs yet.</p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 dark:border-slate-700">
                        <div
                            v-for="job in jobs.data"
                            :key="job.id"
                            class="border-b border-slate-200 px-3 py-3 text-xs dark:border-slate-800"
                            :class="selectedCommand?.id === job.id ? 'bg-cyan-50 dark:bg-cyan-950/20' : ''"
                        >
                            <button
                                type="button"
                                class="block w-full text-left hover:bg-slate-50 dark:hover:bg-slate-800/50"
                                @click="openCommand(job.id)"
                            >
                                <p class="font-mono text-cyan-700 dark:text-cyan-300">{{ job.command }}</p>
                                <p class="mt-1 text-slate-500 dark:text-slate-400">{{ job.server?.name }} | {{ job.status }} | {{ job.risk_level }}</p>
                            </button>
                            <div class="mt-2 flex justify-end">
                                <button
                                    type="button"
                                    class="rounded border border-red-300 px-2 py-1 text-[11px] text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/20"
                                    @click.stop="deleteCommand(job.id)"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </section>

            <section class="flex min-h-[82vh] max-h-[82vh] overflow-auto flex-col rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div v-if="!selectedCommandRef" class="text-sm text-slate-500 dark:text-slate-400">Select a command from left side to open chat history.</div>
                <template v-else>
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold">Chat History: {{ selectedCommandRef.uuid }}</h2>
                        <div class="flex gap-2">
                            <button class="rounded border border-slate-300 px-3 py-1 text-xs dark:border-slate-700" @click="router.post(route('commands.retry', selectedCommandRef.id))">Retry</button>
                            <a :href="route('commands.report', selectedCommandRef.id)" class="rounded border border-slate-300 px-3 py-1 text-xs dark:border-slate-700">Report</a>
                            <button class="rounded bg-slate-800 px-3 py-1 text-xs text-white dark:bg-slate-200 dark:text-slate-900" @click="closeChat">End & Close</button>
                        </div>
                    </div>

                    <div v-if="stepStatuses.length" class="mb-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Installer Steps</p>
                        <div class="space-y-1">
                            <div v-for="step in stepStatuses" :key="`step-${step.step_no}`" class="flex items-center justify-between rounded border border-slate-200 px-2 py-1 text-xs dark:border-slate-800">
                                <p class="font-mono">{{ String(step.step_no).padStart(2, '0') }} {{ step.label }}</p>
                                <span class="rounded px-2 py-0.5 capitalize"
                                    :class="step.status === 'running'
                                        ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200'
                                        : (step.status === 'success'
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200'
                                            : (step.status === 'failed'
                                                ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200'
                                                : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'))"
                                >{{ step.status }}</span>
                            </div>
                        </div>
                    </div>

                    <div ref="chatFeedRef" class="flex-1 space-y-4 overflow-y-auto pr-1">
                        <article v-for="item in chatItems" :key="item.id" class="flex" :class="item.role === 'user' ? 'justify-end' : 'justify-start'">
                            <div
                                class="max-w-[92%] rounded-2xl px-4 py-3 text-sm"
                                :class="item.role === 'user'
                                    ? 'bg-cyan-600 text-white'
                                    : (item.role === 'assistant'
                                        ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100'
                                        : (item.role === 'system'
                                            ? 'bg-slate-900 text-emerald-300'
                                            : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100'))"
                            >
                                <p class="text-xs font-semibold opacity-80">{{ item.title }}</p>
                                <pre class="mt-1 whitespace-pre-wrap break-words font-mono text-xs">{{ item.body }}</pre>
                                <p class="mt-2 text-[11px] opacity-70">{{ item.meta || '-' }}</p>
                            </div>
                        </article>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2 text-xs" v-if="selectedCommandRef.status === 'pending_approval' && canApprove">
                        <button class="rounded bg-emerald-600 px-3 py-2 font-semibold text-white" @click="router.post(route('commands.approve', selectedCommandRef.id))">Approve Command</button>
                        <button class="rounded bg-red-600 px-3 py-2 font-semibold text-white" @click="router.post(route('commands.cancel', selectedCommandRef.id))">Cancel</button>
                    </div>
                </template>

                <form class="mt-3 space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50" @submit.prevent="runNewCommand">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Terminal Input</p>
                    <select v-model="commandForm.server_id" class="w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                        <option value="" disabled>Select server</option>
                        <option v-for="server in servers" :key="`cmd-server-${server.id}`" :value="server.id">{{ server.name }}</option>
                    </select>
                    <textarea v-model="commandForm.command" rows="3" class="w-full rounded border border-slate-300 px-3 py-2 text-sm font-mono dark:border-slate-700 dark:bg-slate-900" placeholder="Type full command (stored for next matching use)"></textarea>
                    <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                        <input v-model="commandForm.auto_fix" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" />
                        Auto replay task: if error, run AI safe fix commands automatically
                    </label>
                    <button class="w-full rounded bg-emerald-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="sending">
                        {{ sending ? 'Sending...' : 'Send Command' }}
                    </button>
                    <p v-if="requestError" class="text-xs text-red-600 dark:text-red-400">{{ requestError }}</p>
                </form>

                <form class="mt-3 space-y-2 rounded-lg border border-cyan-200 bg-cyan-50 p-3 dark:border-cyan-900/60 dark:bg-cyan-950/20" @submit.prevent="buildAdminUserCommand">
                    <p class="text-xs font-semibold uppercase tracking-wide text-cyan-800 dark:text-cyan-200">Admin User Helper</p>
                    <p class="text-xs text-cyan-700 dark:text-cyan-300">Builds a `panel user:create` command with password, email, and root-login choice.</p>
                    <input v-model="adminUserForm.username" type="text" class="w-full rounded border border-cyan-200 px-3 py-2 text-sm dark:border-cyan-900 dark:bg-slate-900" placeholder="Username" />
                    <input v-model="adminUserForm.panel_email" type="email" class="w-full rounded border border-cyan-200 px-3 py-2 text-sm dark:border-cyan-900 dark:bg-slate-900" placeholder="Panel email" />
                    <input v-model="adminUserForm.panel_password" type="text" class="w-full rounded border border-cyan-200 px-3 py-2 text-sm font-mono dark:border-cyan-900 dark:bg-slate-900" placeholder="Panel password" />
                    <input v-model="adminUserForm.shell" type="text" class="w-full rounded border border-cyan-200 px-3 py-2 text-sm font-mono dark:border-cyan-900 dark:bg-slate-900" placeholder="/bin/bash" />
                    <label class="flex items-center gap-2 text-xs text-cyan-900 dark:text-cyan-100">
                        <input v-model="adminUserForm.disable_root" type="checkbox" class="rounded border-cyan-300 dark:border-cyan-700" />
                        Disable SSH root login
                    </label>
                    <button class="w-full rounded bg-cyan-700 px-3 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                        Build Admin User Command
                    </button>
                    <p class="text-[11px] text-cyan-700 dark:text-cyan-300">Result is inserted into the terminal command box, then you can submit it to the selected server.</p>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
