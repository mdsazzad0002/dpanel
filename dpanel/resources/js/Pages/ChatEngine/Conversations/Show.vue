<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
});

const toggleForm = useForm({});
const replyForm = useForm({ content: '' });
const messageSearch = ref('');
const directionFilter = ref('');
const typeFilter = ref('');
const senderFilter = ref('');
const statusFilter = ref('');
const filteredMessages = computed(() => {
    const needle = messageSearch.value.trim().toLowerCase();

    return props.messages.filter((message) => (
        (!needle || String(message.content || '').toLowerCase().includes(needle))
        && (!directionFilter.value || message.direction === directionFilter.value)
        && (!typeFilter.value || message.type === typeFilter.value)
        && (!senderFilter.value || message.role === senderFilter.value)
        && (!statusFilter.value || message.status === statusFilter.value)
    ));
});

const toggleAi = () => {
    toggleForm.patch(panelRoute('chat-engine.conversations.toggle-ai', { conversation: props.conversation.id }));
};

const sendReply = () => {
    replyForm.post(panelRoute('chat-engine.conversations.reply', { conversation: props.conversation.id }), {
        preserveScroll: true,
        onSuccess: () => replyForm.reset('content'),
    });
};
</script>

<template>
    <Head :title="`Conversation — ${conversation.contact_name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">{{ conversation.contact_name }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">via {{ conversation.channel_name }}</p>
                </div>
                <button @click="toggleAi" class="rounded px-3 py-1.5 text-sm font-medium" :class="conversation.is_ai_enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                    AI Auto-reply: {{ conversation.is_ai_enabled ? 'On' : 'Off' }}
                </button>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="messages.length" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-slate-700 dark:bg-slate-800">
                <input v-model="messageSearch" type="search" placeholder="Search message text…" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <select v-model="directionFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">Any direction</option><option value="inbound">Inbound</option><option value="outbound">Outbound</option>
                </select>
                <select v-model="typeFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">Any type</option><option value="text">Message</option><option value="comment">Comment</option>
                </select>
                <select v-model="senderFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">Any sender</option><option value="user">User</option><option value="assistant">AI</option><option value="agent">Agent</option>
                </select>
                <select v-model="statusFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">Any status</option><option value="sent">Sent</option><option value="failed">Failed</option>
                </select>
            </div>

            <div class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <div v-for="m in filteredMessages" :key="m.id" class="flex" :class="m.direction === 'inbound' ? 'justify-start' : 'justify-end'">
                    <div
                        class="max-w-md rounded-lg px-3 py-2 text-sm"
                        :class="m.direction === 'inbound' ? 'bg-slate-100 dark:bg-slate-700' : (m.role === 'agent' ? 'bg-blue-100 dark:bg-blue-900/40' : 'bg-emerald-100 dark:bg-emerald-900/40')"
                    >
                        <p class="whitespace-pre-wrap">{{ m.content }}</p>
                        <p class="mt-1 text-[10px] uppercase tracking-wide text-slate-400">
                            {{ m.role }}<span v-if="m.type === 'comment'"> · comment</span> · {{ m.created_at }}<span v-if="m.status === 'failed'" class="text-red-500"> · failed</span>
                        </p>
                    </div>
                </div>
                <p v-if="!messages.length" class="text-center text-sm text-slate-400">No messages yet.</p>
                <p v-else-if="!filteredMessages.length" class="text-center text-sm text-slate-400">No messages match the selected filters.</p>
            </div>

            <form @submit.prevent="sendReply" class="flex gap-2">
                <input v-model="replyForm.content" type="text" placeholder="Type a manual reply..." class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <button type="submit" :disabled="replyForm.processing || !replyForm.content" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Send</button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
