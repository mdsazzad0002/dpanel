<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChannelForm from './ChannelForm.vue';
import { Head, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

defineProps({
    facebookWebhookUrl: { type: String, default: '' },
});
</script>

<template>
    <Head title="Connect Channel" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Connect Channel</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Connect a Telegram bot or a Facebook Page for AI auto-reply.</p>
            </div>
        </template>

        <div class="max-w-2xl">
            <div v-if="page.props.flash?.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div class="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <ChannelForm
                    :facebook-webhook-url="facebookWebhookUrl"
                    @cancel="router.visit(panelRoute('chat-engine.channels.index'))"
                    @saved="router.visit(panelRoute('chat-engine.channels.index'))"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
