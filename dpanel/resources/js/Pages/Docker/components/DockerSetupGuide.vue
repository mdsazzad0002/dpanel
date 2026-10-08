<script setup>
import { ref } from 'vue';

// Docker is an optional add-on: the main installer never installs it, so this
// explains what it costs and how to add it from the server's shell.
defineProps({
    // 'missing' when Docker is not installed, 'stopped' when its service is down.
    state: { type: String, default: 'missing' },
    refreshing: { type: Boolean, default: false },
});

defineEmits(['refresh']);

const docsUrl = 'https://github.com/mdsazzad0002/dpanel/blob/main/docs/docker.md';
const copied = ref('');

const copy = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = text;
        setTimeout(() => { if (copied.value === text) copied.value = ''; }, 2000);
    } catch {
        copied.value = '';
    }
};

const costs = [
    { label: 'RAM while idle', value: '~90 MB', note: 'dockerd + containerd' },
    { label: 'CPU while idle', value: '~0%', note: 'until a container runs' },
    { label: 'Disk', value: '~350 MB', note: 'plus every image you pull' },
    { label: 'Logs', value: '30 MB max', note: 'per container (3 × 10 MB)' },
];

const checks = [
    'At least 1 GB RAM (2 GB recommended) and 3 GB free disk',
    'Supported OS: Ubuntu, Debian, Rocky or AlmaLinux',
    'Docker Hub is reachable, and the drust service is running',
];
</script>

<template>
    <section v-if="state === 'stopped'" class="rounded-xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-900 dark:bg-amber-950/40">
        <h2 class="text-base font-semibold text-amber-900 dark:text-amber-100">Docker is installed but not running</h2>
        <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">Containers cannot start or be managed until the Docker service is back. On the server, run:</p>
        <div class="mt-3 space-y-2">
            <div v-for="cmd in ['sudo systemctl start docker', 'sudo dpanel docker status']" :key="cmd" class="flex items-center gap-2 rounded-md bg-slate-950 px-3 py-2 font-mono text-xs text-slate-100">
                <span class="flex-1 select-all">{{ cmd }}</span>
                <button type="button" class="rounded border border-slate-600 px-2 py-0.5 text-[11px] hover:bg-slate-800" @click="copy(cmd)">{{ copied === cmd ? 'Copied' : 'Copy' }}</button>
            </div>
        </div>
        <button type="button" :disabled="refreshing" class="mt-4 rounded-md border border-amber-300 px-3 py-2 text-xs hover:bg-amber-100 disabled:opacity-60 dark:border-amber-800 dark:hover:bg-amber-900" @click="$emit('refresh')">
            {{ refreshing ? 'Checking…' : 'Check again' }}
        </button>
    </section>

    <section v-else class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">Optional add-on · Off</span>
                <h2 class="mt-2 text-lg font-semibold">Docker is not installed on this server</h2>
                <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                    Docker lets admins run apps as containers (Redis, n8n, Uptime Kuma, your own images) next to your websites.
                    dPanel does not install it by default, so servers that never use containers do not pay for it.
                </p>
            </div>
            <a :href="docsUrl" target="_blank" rel="noopener" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Read the Docker guide ↗</a>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            <div v-for="cost in costs" :key="cost.label" class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <div class="text-xs text-slate-500 dark:text-slate-400">{{ cost.label }}</div>
                <div class="mt-1 text-lg font-semibold">{{ cost.value }}</div>
                <div class="text-[11px] text-slate-500 dark:text-slate-400">{{ cost.note }}</div>
            </div>
        </div>

        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold">Install it</h3>
                <ol class="mt-2 space-y-3 text-sm">
                    <li>
                        <span class="text-slate-500 dark:text-slate-400">1. Connect to the server over SSH and run:</span>
                        <div class="mt-1 flex items-center gap-2 rounded-md bg-slate-950 px-3 py-2 font-mono text-xs text-slate-100">
                            <span class="flex-1 select-all">sudo dpanel docker</span>
                            <button type="button" class="rounded border border-slate-600 px-2 py-0.5 text-[11px] hover:bg-slate-800" @click="copy('sudo dpanel docker')">{{ copied === 'sudo dpanel docker' ? 'Copied' : 'Copy' }}</button>
                        </div>
                    </li>
                    <li class="text-slate-500 dark:text-slate-400">2. It checks the server, shows the cost and asks before installing anything.</li>
                    <li class="text-slate-500 dark:text-slate-400">3. Come back here and press <strong class="text-slate-700 dark:text-slate-200">Check again</strong>. Docker then shows in the menu.</li>
                </ol>
                <button type="button" :disabled="refreshing" class="mt-4 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40" @click="$emit('refresh')">
                    {{ refreshing ? 'Checking…' : 'Check again' }}
                </button>
            </div>
            <div>
                <h3 class="text-sm font-semibold">The installer checks</h3>
                <ul class="mt-2 space-y-1.5 text-sm text-slate-500 dark:text-slate-400">
                    <li v-for="check in checks" :key="check" class="flex gap-2"><span class="text-emerald-600">✓</span>{{ check }}</li>
                </ul>
                <h3 class="mt-4 text-sm font-semibold">Safe defaults</h3>
                <ul class="mt-2 space-y-1.5 text-sm text-slate-500 dark:text-slate-400">
                    <li class="flex gap-2"><span class="text-emerald-600">✓</span>Only admins can manage Docker, and every action is logged.</li>
                    <li class="flex gap-2"><span class="text-emerald-600">✓</span>Container ports listen on 127.0.0.1 unless you mark them public.</li>
                    <li class="flex gap-2"><span class="text-emerald-600">✓</span>Remove it any time: <code class="text-xs">sudo dpanel docker remove</code> (your data is kept).</li>
                </ul>
            </div>
        </div>
    </section>
</template>
