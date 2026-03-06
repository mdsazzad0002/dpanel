<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';

const props = defineProps({
    quickCommands: {
        type: Array,
        default: () => [],
    },
    currentDir: {
        type: String,
        default: '',
    },
});

const page = usePage();
const commandHistory = ref([]);
const HISTORY_KEY = 'serverpanel-terminal-history';
const CWD_KEY = 'serverpanel-terminal-cwd';
const REMEMBER_PROMPT_KEY = 'serverpanel-terminal-remember-prompt';
const historyCursor = ref(-1);
const rememberPrompt = ref(true);
const isRunning = ref(false);
const commandError = ref('');
const errorMessage = ref('');

const form = reactive({
    command: '',
    cwd: props.currentDir || '',
});

const terminalResult = ref({
    command: '',
    output: [],
    exit_code: null,
    executed_at: '-',
    current_dir: props.currentDir || '',
});

const promptUser = computed(() => {
    return String(page.props.auth?.user?.name ?? 'user').toLowerCase().replace(/\s+/g, '_');
});

const promptPath = ref('~/server');

const suggestionPool = computed(() => {
    const common = [
        'ls',
        'ls -la',
        'pwd',
        'cd ..',
        'cd ~',
        'git status',
        'composer install',
        'php artisan migrate',
        'php artisan optimize:clear',
        'npm run build',
    ];

    return [...new Set([...props.quickCommands, ...commandHistory.value, ...common])];
});

const commandSuggestions = computed(() => {
    const input = form.command.trim().toLowerCase();
    if (!input) return suggestionPool.value.slice(0, 8);

    return suggestionPool.value
        .filter((item) => item.toLowerCase().includes(input))
        .slice(0, 8);
});

const normalizeTerminalPayload = (payload) => {
    if (!payload || typeof payload !== 'object') {
        return {
            command: '',
            output: [],
            exit_code: null,
            executed_at: '-',
            current_dir: promptPath.value,
        };
    }

    return {
        command: String(payload.command || ''),
        output: Array.isArray(payload.output) ? payload.output.map((line) => String(line)) : [],
        exit_code: payload.exit_code ?? null,
        executed_at: String(payload.executed_at || '-'),
        current_dir: String(payload.current_dir || promptPath.value || props.currentDir || ''),
    };
};

const runCommand = async () => {
    const command = form.command.trim();
    if (!command) return;
    form.cwd = promptPath.value;
    commandError.value = '';
    errorMessage.value = '';
    isRunning.value = true;

    try {
        const response = await window.axios.post(
            route('terminal.execute'),
            {
                command,
                cwd: form.cwd,
            },
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        const payload = normalizeTerminalPayload(response?.data?.terminal);
        terminalResult.value = payload;
        errorMessage.value = String(response?.data?.error || '');
        const nextDir = String(payload.current_dir || promptPath.value || props.currentDir || '');
        if (nextDir) {
            promptPath.value = nextDir;
            form.cwd = nextDir;
            if (rememberPrompt.value) {
                localStorage.setItem(CWD_KEY, nextDir);
            }
        }

        commandHistory.value = [command, ...commandHistory.value.filter((item) => item !== command)].slice(0, 15);
        localStorage.setItem(HISTORY_KEY, JSON.stringify(commandHistory.value));
        historyCursor.value = -1;
        form.command = '';
    } catch (error) {
        const status = error?.response?.status;
        const responseErrors = error?.response?.data?.errors;
        const message = error?.response?.data?.message;
        const responseError = error?.response?.data?.error;
        const responseTerminal = error?.response?.data?.terminal;

        if (status === 422 && responseErrors?.command?.length) {
            commandError.value = String(responseErrors.command[0]);
        } else {
            errorMessage.value = String(responseError || message || 'Failed to execute command.');
        }

        if (responseTerminal) {
            terminalResult.value = normalizeTerminalPayload(responseTerminal);
            const nextDir = String(terminalResult.value.current_dir || promptPath.value || props.currentDir || '');
            if (nextDir) {
                promptPath.value = nextDir;
                form.cwd = nextDir;
            }
        }
    } finally {
        isRunning.value = false;
    }
};

const useQuickCommand = (command) => {
    form.command = command;
};

const useHistoryCommand = (command) => {
    form.command = command;
};

const useSuggestionCommand = (command) => {
    form.command = command;
};

const handleCommandKeydown = (event) => {
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (commandHistory.value.length === 0) return;
        const next = Math.min(historyCursor.value + 1, commandHistory.value.length - 1);
        historyCursor.value = next;
        form.command = commandHistory.value[next] ?? form.command;
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (commandHistory.value.length === 0) return;
        const next = historyCursor.value - 1;
        if (next < 0) {
            historyCursor.value = -1;
            form.command = '';
            return;
        }

        historyCursor.value = next;
        form.command = commandHistory.value[next] ?? '';
    }
};

onMounted(() => {
    const rememberRaw = localStorage.getItem(REMEMBER_PROMPT_KEY);
    rememberPrompt.value = rememberRaw === null ? true : rememberRaw === '1';

    const raw = localStorage.getItem(HISTORY_KEY);
    if (raw) {
        try {
            const parsed = JSON.parse(raw);
            commandHistory.value = Array.isArray(parsed) ? parsed.slice(0, 15) : [];
        } catch {
            commandHistory.value = [];
        }
    }

    const storedDir = localStorage.getItem(CWD_KEY);
    const flashDir = String(page.props.flash?.terminal?.current_dir || '');
    promptPath.value = flashDir || (rememberPrompt.value ? storedDir : '') || props.currentDir || promptPath.value;
    form.cwd = promptPath.value;
    errorMessage.value = String(page.props.flash?.error || '');

    if (page.props.flash?.terminal) {
        terminalResult.value = normalizeTerminalPayload(page.props.flash.terminal);
    }
});

watch(
    () => page.props.flash?.terminal,
    (terminal) => {
        if (!terminal) return;
        terminalResult.value = normalizeTerminalPayload(terminal);
        const normalized = String(terminalResult.value.current_dir || '').trim();
        if (!normalized) return;
        promptPath.value = normalized;
        form.cwd = normalized;
        if (rememberPrompt.value) {
            localStorage.setItem(CWD_KEY, normalized);
        }
    },
);

watch(rememberPrompt, (enabled) => {
    localStorage.setItem(REMEMBER_PROMPT_KEY, enabled ? '1' : '0');
    if (!enabled) {
        localStorage.removeItem(CWD_KEY);
    } else if (promptPath.value) {
        localStorage.setItem(CWD_KEY, promptPath.value);
    }
});
</script>

<template>
    <Head title="Terminal" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Server Terminal</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Git Bash style command console.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="errorMessage" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ errorMessage }}
            </div>

            <div class="rounded-xl border border-slate-800 bg-[#0d1117] text-slate-100 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-700 px-4 py-2">
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full bg-red-500"></span>
                        <span class="h-3 w-3 rounded-full bg-yellow-400"></span>
                        <span class="h-3 w-3 rounded-full bg-green-500"></span>
                    </div>
                    <p class="font-mono text-xs text-slate-400">MINGW64</p>
                </div>

                <div class="space-y-4 p-4">
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Quick Commands</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="command in quickCommands"
                                :key="command"
                                type="button"
                                class="rounded-md border border-slate-600 bg-slate-900 px-3 py-1.5 font-mono text-xs text-emerald-300 hover:bg-slate-800"
                                @click="useQuickCommand(command)"
                            >
                                {{ command }}
                            </button>
                        </div>
                        <label class="mt-3 inline-flex cursor-pointer items-center gap-2 text-xs text-slate-300">
                            <input
                                v-model="rememberPrompt"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-emerald-500 focus:ring-emerald-500"
                            />
                            Remember prompt path
                        </label>
                    </div>

                    <div class="flex h-[26rem] flex-col rounded-lg border border-slate-700 bg-black/50">
                        <div class="flex items-center justify-between border-b border-slate-800 px-3 py-2 text-xs text-slate-400">
                            <p>Output</p>
                            <p>{{ terminalResult.executed_at || '-' }}</p>
                        </div>

                        <div class="flex-1 overflow-y-auto p-3 font-mono text-xs">
                            <p class="mb-2 text-slate-300">
                                <span class="text-emerald-400">{{ promptUser }}@server</span>
                                <span class="text-violet-300">{{ promptPath }}</span>
                                <span class="text-slate-300">$</span>
                                {{ terminalResult.command || '' }}
                            </p>
                            <p v-if="!terminalResult.command" class="text-slate-500">No command executed yet.</p>
                            <pre v-else-if="terminalResult.output?.length" class="whitespace-pre-wrap break-words text-emerald-300">{{ terminalResult.output.join('\n') }}</pre>
                            <p v-else class="text-slate-500">No output</p>
                            <p v-if="terminalResult.command" class="mt-2 text-slate-400">Exit: {{ terminalResult.exit_code ?? '-' }}</p>
                        </div>

                        <form class="border-t border-slate-800 bg-black/40 p-3" @submit.prevent="runCommand">
                            <div class="flex items-center gap-2 font-mono text-sm">
                                <span class="text-emerald-400">{{ promptUser }}@server</span>
                                <span class="text-violet-300">{{ promptPath }}</span>
                                <span class="text-slate-300">$</span>
                                <input
                                    v-model="form.command"
                                    type="text"
                                    placeholder="type command and press Enter"
                                    class="w-full border-0 bg-transparent p-0 text-sm text-slate-100 outline-none ring-0 placeholder:text-slate-500"
                                    @keydown="handleCommandKeydown"
                                />
                                <button
                                    type="submit"
                                    :disabled="isRunning"
                                    class="rounded-md border border-emerald-500 px-3 py-1 text-xs text-emerald-300 hover:bg-emerald-900/20 disabled:opacity-60"
                                >
                                    Run
                                </button>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button
                                    v-for="suggestion in commandSuggestions"
                                    :key="suggestion"
                                    type="button"
                                    class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1 font-mono text-[11px] text-slate-300 hover:bg-slate-800"
                                    @click="useSuggestionCommand(suggestion)"
                                >
                                    {{ suggestion }}
                                </button>
                            </div>
                            <p v-if="commandError" class="mt-2 text-xs text-red-400">{{ commandError }}</p>
                        </form>
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">History</p>
                        <div v-if="commandHistory.length === 0" class="text-xs text-slate-500">No command history yet.</div>
                        <div v-else class="flex flex-wrap gap-2">
                            <button
                                v-for="(command, index) in commandHistory"
                                :key="`${command}-${index}`"
                                type="button"
                                class="rounded-md border border-slate-600 bg-slate-900 px-2 py-1 font-mono text-xs text-slate-200 hover:bg-slate-800"
                                @click="useHistoryCommand(command)"
                            >
                                {{ command }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
