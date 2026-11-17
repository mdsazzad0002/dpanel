<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    tasks: { type: Object, default: null },
    servers: { type: Array, default: () => [] },
    createOnly: { type: Boolean, default: false },
});

const form = useForm({
    server_id: props.servers[0]?.id || '',
    title: '',
    goal: '',
    priority: 'medium',
});

const submit = () => form.post(route('server-tasks.store'));
</script>

<template>
    <Head :title="t('serverPanel.tasks.title')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-lg font-semibold">{{ t('serverPanel.tasks.title') }}</h1></template>

        <div class="grid gap-4 xl:grid-cols-[1.2fr_2fr]">
            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">{{ t('serverPanel.tasks.create') }}</h2>
                <form class="mt-3 space-y-3" @submit.prevent="submit">
                    <select v-model="form.server_id" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm"><option v-for="server in servers" :key="server.id" :value="server.id">{{ server.name }}</option></select>
                    <input v-model="form.title" type="text" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm" :placeholder="t('serverPanel.tasks.taskTitle')" />
                    <textarea v-model="form.goal" rows="5" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm" :placeholder="t('serverPanel.tasks.taskGoal')" />
                    <select v-model="form.priority" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm"><option value="critical">{{ t('serverPanel.tasks.priority.critical') }}</option><option value="high">{{ t('serverPanel.tasks.priority.high') }}</option><option value="medium">{{ t('serverPanel.tasks.priority.medium') }}</option><option value="low">{{ t('serverPanel.tasks.priority.low') }}</option><option value="info">{{ t('serverPanel.tasks.priority.info') }}</option></select>
                    <button class="rounded bg-cyan-700 px-3 py-2 text-sm font-semibold text-white">{{ t('serverPanel.tasks.create') }}</button>
                </form>
            </section>

            <section v-if="!createOnly" class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">{{ t('serverPanel.tasks.tasks') }}</h2>
                <div class="mt-3 space-y-2 text-xs">
                    <article v-for="task in tasks.data" :key="task.id" class="rounded border border-slate-200 p-3">
                        <div class="flex items-center justify-between">
                            <p class="font-semibold">{{ task.title }}</p>
                            <span class="rounded bg-slate-100 px-2 py-1">{{ task.status }}</span>
                        </div>
                        <p class="text-slate-500">{{ task.server?.name }} - {{ task.priority }}</p>
                        <Link :href="route('server-tasks.show', task.id)" class="text-cyan-700">{{ t('serverPanel.tasks.open') }}</Link>
                    </article>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
