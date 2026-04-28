<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    server: { type: Object, required: true },
    recentConnectionTests: { type: Array, default: () => [] },
    recentCommands: { type: Array, default: () => [] },
});

const tab = ref('overview');

const trigger = (action) => {
    const routeName = action === 'test' ? 'servers.test-connection' : 'servers.scan';
    router.post(route(routeName, props.server.id));
};
</script>

<template>
    <Head :title="`Server ${server.name}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold">{{ server.name }} ({{ server.host }})</h1>
                <div class="flex gap-2 text-xs">
                    <button class="rounded border border-slate-300 px-3 py-1 hover:bg-slate-100" @click="trigger('test')">Test Connection</button>
                    <button class="rounded border border-slate-300 px-3 py-1 hover:bg-slate-100" @click="trigger('scan')">Scan</button>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex flex-wrap gap-2 text-xs">
                <button class="rounded px-3 py-1" :class="tab === 'overview' ? 'bg-cyan-700 text-white' : 'bg-slate-100'" @click="tab = 'overview'">Overview</button>
                <Link :href="route('servers.terminal', server.id)" class="rounded bg-slate-100 px-3 py-1">AI Terminal</Link>
                <button class="rounded px-3 py-1" :class="tab === 'history' ? 'bg-cyan-700 text-white' : 'bg-slate-100'" @click="tab = 'history'">Command History</button>
                <button class="rounded px-3 py-1" :class="tab === 'resolver' ? 'bg-cyan-700 text-white' : 'bg-slate-100'" @click="tab = 'resolver'">Error Resolver</button>
                <Link :href="route('ssh-memories.index')" class="rounded bg-slate-100 px-3 py-1">Memories</Link>
                <button class="rounded px-3 py-1" :class="tab === 'reports' ? 'bg-cyan-700 text-white' : 'bg-slate-100'" @click="tab = 'reports'">Reports</button>
                <Link :href="route('servers.edit', server.id)" class="rounded bg-slate-100 px-3 py-1">Settings</Link>
            </div>

            <section v-if="tab === 'overview'" class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="grid gap-3 md:grid-cols-3 text-sm">
                    <div><span class="text-slate-500">Status:</span> {{ server.status }}</div>
                    <div><span class="text-slate-500">Mode:</span> {{ server.mode }}</div>
                    <div><span class="text-slate-500">Username:</span> {{ server.username }}</div>
                    <div><span class="text-slate-500">OS:</span> {{ server.os_name || '-' }}</div>
                    <div><span class="text-slate-500">Kernel:</span> {{ server.kernel || '-' }}</div>
                    <div><span class="text-slate-500">Architecture:</span> {{ server.architecture || '-' }}</div>
                    <div><span class="text-slate-500">CPU Cores:</span> {{ server.cpu_cores || '-' }}</div>
                    <div><span class="text-slate-500">RAM:</span> {{ server.ram_total_mb || '-' }} MB</div>
                    <div><span class="text-slate-500">Disk:</span> {{ server.disk_total_gb || '-' }} GB</div>
                </div>
            </section>

            <section v-if="tab === 'history'" class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold">Recent Commands</h2>
                <div class="mt-3 space-y-2 text-xs">
                    <article v-for="cmd in recentCommands" :key="cmd.id" class="rounded border border-slate-200 p-3">
                        <p class="font-mono">{{ cmd.command }}</p>
                        <p class="mt-1 text-slate-500">{{ cmd.status }} - {{ cmd.risk_level }}</p>
                        <Link :href="route('commands.show', cmd.id)" class="text-cyan-700">Open</Link>
                    </article>
                </div>
            </section>

            <section v-if="tab === 'resolver'" class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <p>AI resolver suggestions are attached to failed command details. Open command history and select a failed command to view suggestions and fix actions.</p>
            </section>

            <section v-if="tab === 'reports'" class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <p>Reports are generated per command and downloadable from command detail page.</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold">Latest Connection Tests</h2>
                <div class="mt-3 space-y-2 text-xs">
                    <article v-for="test in recentConnectionTests" :key="test.id" class="rounded border border-slate-200 p-3">
                        <p class="font-semibold">{{ test.status }} - {{ test.tested_at }}</p>
                        <pre class="mt-2 overflow-x-auto bg-slate-950 p-2 text-emerald-300">{{ test.output || test.error_output }}</pre>
                    </article>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
