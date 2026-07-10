<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    stats: { type: Object, required: true },
    servers: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="ServerPanel Control Center" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">ServerPanel Control Center</h1>
                <p class="text-sm text-slate-500">Unified view for servers, commands, and tasks.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">Servers</p><p class="text-2xl font-semibold">{{ stats.servers_total }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">Online</p><p class="text-2xl font-semibold">{{ stats.servers_online }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">Running Tasks</p><p class="text-2xl font-semibold">{{ stats.tasks_running }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">Failed Tasks</p><p class="text-2xl font-semibold">{{ stats.tasks_failed }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">Terminal</p><p class="text-2xl font-semibold">Removed</p></div>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold">Recent Servers</h2>
                        <Link :href="route('servers.index')" class="text-xs text-cyan-700">Open All</Link>
                    </div>
                    <div class="space-y-2 text-xs">
                        <div v-for="server in servers" :key="server.id" class="rounded border border-slate-200 p-2">
                            <p class="font-semibold">{{ server.name }} ({{ server.host }})</p>
                            <p class="text-slate-500">{{ server.status }} | {{ server.mode }}</p>
                        </div>
                    </div>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold">Command Terminal</h2>
                    </div>
                    <p class="text-xs text-slate-600">
                        SSH terminal and memory-backed command chat have been removed for safety. Use Servers, Tasks, and other management screens instead.
                    </p>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold">Recent Tasks</h2>
                        <Link :href="route('server-tasks.index')" class="text-xs text-cyan-700">Open All</Link>
                    </div>
                    <div class="space-y-2 text-xs">
                        <div v-for="task in tasks" :key="task.id" class="rounded border border-slate-200 p-2">
                            <p class="font-semibold">{{ task.title }}</p>
                            <p class="text-slate-500">{{ task.server?.name }} | {{ task.status }} | {{ task.priority }}</p>
                        </div>
                    </div>
                </article>

            </section>
        </div>
    </AuthenticatedLayout>
</template>
