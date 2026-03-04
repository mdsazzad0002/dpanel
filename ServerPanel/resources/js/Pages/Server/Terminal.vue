<script setup>
import { computed, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    quickCommands: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const commandHistory = ref([]);
const HISTORY_KEY = 'serverpanel-terminal-history';

const form = useForm({
    command: '',
});

const promptUser = computed(() => {
    return String(page.props.auth?.user?.name ?? 'user').toLowerCase().replace(/\s+/g, '_');
});

const promptPath = ref('~/server');

const runCommand = () => {
    const command = form.command.trim();
    if (!command) return;

    form.post(route('terminal.execute'), {
        preserveScroll: true,
        onSuccess: () => {
            commandHistory.value = [command, ...commandHistory.value.filter((item) => item !== command)].slice(0, 15);
            localStorage.setItem(HISTORY_KEY, JSON.stringify(commandHistory.value));
            form.reset('command');
        },
    });
};

const useQuickCommand = (command) => {
    form.command = command;
};

const useHistoryCommand = (command) => {
    form.command = command;
};

onMounted(() => {
    const raw = localStorage.getItem(HISTORY_KEY);
    if (!raw) return;

    try {
        const parsed = JSON.parse(raw);
        commandHistory.value = Array.isArray(parsed) ? parsed.slice(0, 15) : [];
    } catch {
        commandHistory.value = [];
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
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
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
                    </div>

                    <form class="rounded-lg border border-slate-700 bg-black/40 p-3" @submit.prevent="runCommand">
                        <div class="flex items-center gap-2 font-mono text-sm">
                            <span class="text-emerald-400">{{ promptUser }}@server</span>
                            <span class="text-violet-300">{{ promptPath }}</span>
                            <span class="text-slate-300">$</span>
                            <input
                                v-model="form.command"
                                type="text"
                                placeholder="type command and press Enter"
                                class="w-full border-0 bg-transparent p-0 text-sm text-slate-100 outline-none ring-0 placeholder:text-slate-500"
                            />
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="rounded-md border border-emerald-500 px-3 py-1 text-xs text-emerald-300 hover:bg-emerald-900/20 disabled:opacity-60"
                            >
                                Run
                            </button>
                        </div>
                        <p v-if="form.errors.command" class="mt-2 text-xs text-red-400">{{ form.errors.command }}</p>
                    </form>

                    <div class="rounded-lg border border-slate-700 bg-black/50 p-3">
                        <div class="mb-2 flex items-center justify-between text-xs text-slate-400">
                            <p>Output</p>
                            <p>{{ page.props.flash?.terminal?.executed_at || '-' }}</p>
                        </div>
                        <div class="max-h-72 overflow-y-auto rounded border border-slate-800 bg-black p-3 font-mono text-xs">
                            <p class="mb-2 text-slate-300">
                                <span class="text-emerald-400">{{ promptUser }}@server</span>
                                <span class="text-violet-300">{{ promptPath }}</span>
                                <span class="text-slate-300">$</span>
                                {{ page.props.flash?.terminal?.command || '' }}
                            </p>
                            <p v-if="!page.props.flash?.terminal?.command" class="text-slate-500">No command executed yet.</p>
                            <pre v-else-if="page.props.flash?.terminal?.output?.length" class="whitespace-pre-wrap break-words text-emerald-300">{{ page.props.flash?.terminal?.output?.join('\n') }}</pre>
                            <p v-else class="text-slate-500">No output</p>
                            <p v-if="page.props.flash?.terminal?.command" class="mt-2 text-slate-400">Exit: {{ page.props.flash?.terminal?.exit_code ?? '-' }}</p>
                        </div>
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
