<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChannelForm from './ChannelForm.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    channel: { type: Object, required: true },
    facebookWebhookUrl: { type: String, default: '' },
});

const reconnectForm = useForm({});
const reconnect = () => {
    reconnectForm.post(panelRoute('chat-engine.channels.reconnect', { channel: props.channel.id }), { preserveScroll: true });
};

const backToIndex = () => router.visit(panelRoute('chat-engine.channels.index'));
</script>

<template>
    <Head title="Edit Channel" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit Channel</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ channel.name }}</p>
            </div>
        </template>

        <div class="max-w-2xl space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <p class="text-sm font-medium">Webhook connection</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Re-registers this channel's webhook using its current credentials. Use this if messages stop arriving.</p>
                </div>
                <button @click="reconnect" :disabled="reconnectForm.processing" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">Reconnect</button>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <ChannelForm
                    :channel="channel"
                    :facebook-webhook-url="facebookWebhookUrl"
                    @cancel="backToIndex"
                    @saved="backToIndex"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
