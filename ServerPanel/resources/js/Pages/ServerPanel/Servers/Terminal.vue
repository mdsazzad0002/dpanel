<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    server: { type: Object, required: true },
    quickCommands: { type: Array, default: () => [] },
    currentDir: { type: String, default: '' },
});

const command = ref('');
const cwd = ref(props.currentDir || '/');
const isRunning = ref(false);
const errorMessage = ref('');
const feed = ref([]);

const promptUser = computed(() =>
    String(props.server?.username || 'server').toLowerCase().replace(/\s+/g, '_'),
);

const runCommand = async () => {
    const input = command.value.trim();
    if (!input || isRunning.value) return;

    errorMessage.value = '';
    isRunning.value = true;

    const entry = {
        id: Date.now(),
        input,
        output: ['Running...'],
        exitCode: null,
        at: new Date().toLocaleString(),
    };
    feed.value.unshift(entry);

    try {
        const response = await window.axios.post(
            route('terminal.execute'),
            { command: input, cwd: cwd.value },
            { headers: { Accept: 'application/json' } },
        );

        const terminal = response?.data?.terminal ?? {};
        entry.output = Array.isArray(terminal.output) && terminal.output.length
            ? terminal.output.map((line) => String(line))
            : ['No output'];
        entry.exitCode = terminal.exit_code ?? 0;
        cwd.value = String(terminal.current_dir || cwd.value || '/');
    } catch (error) {
        const responseError = error?.response?.data?.error;
        const message = error?.response?.data?.message;
        const terminal = error?.response?.data?.terminal;
        entry.output = Array.isArray(terminal?.output) && terminal.output.length
            ? terminal.output.map((line) => String(line))
            : [String(responseError || message || 'Execution failed.')];
        entry.exitCode = terminal?.exit_code ?? 1;
        errorMessage.value = String(responseError || message || 'Execution failed.');
        if (terminal?.current_dir) {
            cwd.value = String(terminal.current_dir);
        }
    } finally {
        command.value = '';
        isRunning.value = false;
    }
};

const useQuickCommand = (value) => {
    command.value = String(value || '');
};
</script>

<template>
    <Head :title="`Realtime Terminal - ${server.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">Realtime Terminal: {{ server.name }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Instant command feedback, no queue flow.</p>
                </div>
                <Link :href="route('servers.show', server.id)" class="text-sm text-cyan-700">Back to Server</Link>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="errorMessage" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ errorMessage }}
            </div>

            <section class="rounded-xl border border-slate-800 bg-[#0d1117] p-4 text-slate-100 shadow-xl">
                <div class="mb-3">
                    <p class="mb-2 text-xs uppercase tracking-wide text-slate-400">Quick Commands</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="item in quickCommands"
                            :key="item"
                            type="button"
                            class="rounded-md border border-slate-600 bg-slate-900 px-3 py-1 text-xs font-mono text-emerald-300 hover:bg-slate-800"
                            @click="useQuickCommand(item)"
                        >
                            {{ item }}
                        </button>
                    </div>
                </div>

                <form class="rounded-lg border border-slate-700 bg-black/40 p-3" @submit.prevent="runCommand">
                    <div class="flex items-center gap-2 font-mono text-sm">
                        <span class="text-emerald-400">{{ promptUser }}@{{ server.host }}</span>
                        <span class="text-violet-300">{{ cwd }}</span>
                        <span>$</span>
                        <input
                            v-model="command"
                            type="text"
                            class="w-full border-0 bg-transparent p-0 text-sm text-slate-100 outline-none ring-0"
                            placeholder="Type command and press Enter"
                        />
                        <button
                            type="submit"
                            :disabled="isRunning"
                            class="rounded-md border border-emerald-500 px-3 py-1 text-xs text-emerald-300 hover:bg-emerald-900/30 disabled:opacity-60"
                        >
                            {{ isRunning ? 'Running' : 'Run' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold">Realtime Feed (Essential I/O)</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Shows only what was given and what was received.</p>

                <div v-if="feed.length === 0" class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                    No command run yet.
                </div>

                <div v-else class="mt-4 space-y-3">
                    <article
                        v-for="item in feed"
                        :key="item.id"
                        class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50"
                    >
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ item.at }}</p>
                        <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Input</p>
                        <p class="font-mono text-sm text-slate-900 dark:text-slate-100">{{ item.input }}</p>
                        <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Output</p>
                        <pre class="whitespace-pre-wrap break-words rounded bg-black/80 p-2 font-mono text-xs text-emerald-300">{{ item.output.join('\n') }}</pre>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Exit Code: {{ item.exitCode ?? '-' }}</p>
                    </article>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
