<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    stats: { type: Object, required: true },
    servers: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="t('serverPanel.controlCenter.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('serverPanel.controlCenter.title') }}</h1>
                <p class="text-sm text-slate-500">{{ t('serverPanel.controlCenter.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">{{ t('serverPanel.controlCenter.servers') }}</p><p class="text-2xl font-semibold">{{ stats.servers_total }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">{{ t('serverPanel.controlCenter.online') }}</p><p class="text-2xl font-semibold">{{ stats.servers_online }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">{{ t('serverPanel.controlCenter.runningTasks') }}</p><p class="text-2xl font-semibold">{{ stats.tasks_running }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">{{ t('serverPanel.controlCenter.failedTasks') }}</p><p class="text-2xl font-semibold">{{ stats.tasks_failed }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">{{ t('serverPanel.controlCenter.terminal') }}</p><p class="text-2xl font-semibold">{{ t('serverPanel.controlCenter.removed') }}</p></div>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold">{{ t('serverPanel.controlCenter.recentServers') }}</h2>
                        <Link :href="route('servers.index')" class="text-xs text-cyan-700">{{ t('serverPanel.controlCenter.openAll') }}</Link>
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
                        <h2 class="text-sm font-semibold">{{ t('serverPanel.controlCenter.commandTerminal') }}</h2>
                    </div>
                    <p class="text-xs text-slate-600">
                        {{ t('serverPanel.controlCenter.terminalRemoved') }}
                    </p>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold">{{ t('serverPanel.controlCenter.recentTasks') }}</h2>
                        <Link :href="route('server-tasks.index')" class="text-xs text-cyan-700">{{ t('serverPanel.controlCenter.openAll') }}</Link>
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
