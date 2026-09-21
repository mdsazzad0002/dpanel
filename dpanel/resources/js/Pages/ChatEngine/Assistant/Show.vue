<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    channel: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
});

const form = useForm({ message: '' });

const send = () => {
    form.post(panelRoute('chat-engine.channels.assistant.send', { channel: props.channel.id }), {
        preserveScroll: true,
        onSuccess: () => form.reset('message'),
    });
};
</script>

<template>
    <Head :title="`Assistant — ${channel.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Assistant — {{ channel.name }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <span v-if="channel.business">{{ channel.business.name }} · </span>Internal chat — tools like sending SMS or bulk due reminders are only available here, never on the public widget.
                </p>
            </div>
        </template>

        <div class="max-w-2xl space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="!channel.internal_access" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                This channel isn't marked "Internal / staff-only" yet — enable that on the channel's Edit page first, otherwise the assistant won't have access to due-reminder or SMS tools here.
            </div>
            <div v-if="!channel.business" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                This channel isn't linked to a Business yet — assign one from the Businesses page first, otherwise the assistant has nothing to act on.
            </div>

            <div class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <div v-for="m in messages" :key="m.id" class="flex" :class="m.direction === 'inbound' ? 'justify-end' : 'justify-start'">
                    <div
                        class="max-w-md rounded-lg px-3 py-2 text-sm"
                        :class="m.direction === 'inbound' ? 'bg-blue-100 dark:bg-blue-900/40' : 'bg-slate-100 dark:bg-slate-700'"
                    >
                        <p class="whitespace-pre-wrap">{{ m.content }}</p>
                        <p class="mt-1 text-[10px] uppercase tracking-wide text-slate-400">{{ m.role }} · {{ m.created_at }}</p>
                    </div>
                </div>
                <p v-if="!messages.length" class="text-center text-sm text-slate-400">No messages yet — ask the assistant something below.</p>
            </div>

            <form @submit.prevent="send" class="flex gap-2">
                <input v-model="form.message" type="text" placeholder="e.g. Who currently has an outstanding due?" class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <button type="submit" :disabled="form.processing || !form.message" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Send</button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
