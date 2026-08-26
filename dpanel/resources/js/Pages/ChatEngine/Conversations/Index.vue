<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    filterChannelId: { type: String, default: null },
});

const search = ref('');
const channelFilter = ref('');
const typeFilter = ref('');
const aiFilter = ref('');
const statusFilter = ref('');
const channels = computed(() => Array.from(new Map(
    props.conversations.map((conversation) => [conversation.channel_name, conversation.channel_name])
).values()).sort());
const filteredConversations = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return props.conversations.filter((conversation) => (
        (!needle || [conversation.contact_name, conversation.channel_name]
            .some((value) => String(value || '').toLowerCase().includes(needle)))
        && (!channelFilter.value || conversation.channel_name === channelFilter.value)
        && (!typeFilter.value || conversation.channel_type === typeFilter.value)
        && (!aiFilter.value || (aiFilter.value === 'auto' ? conversation.is_ai_enabled : !conversation.is_ai_enabled))
        && (!statusFilter.value || conversation.status === statusFilter.value)
    ));
});
</script>

<template>
    <Head title="Conversations" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Conversations</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Inbox across all connected channels.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>

            <div v-if="filterChannelId" class="flex items-center justify-between rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-300">
                <span>Showing conversations for one channel only.</span>
                <Link :href="panelRoute('chat-engine.conversations.index')" class="font-medium hover:underline">Clear filter</Link>
            </div>

            <div v-if="conversations.length" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-slate-700 dark:bg-slate-800">
                <input v-model="search" type="search" placeholder="Search contact or channel…" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <select v-model="channelFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">All channels</option>
                    <option v-for="channel in channels" :key="channel" :value="channel">{{ channel }}</option>
                </select>
                <select v-model="typeFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">All types</option><option value="facebook">Facebook</option><option value="telegram">Telegram</option><option value="whatsapp">WhatsApp</option><option value="instagram">Instagram</option><option value="slack">Slack</option>
                </select>
                <select v-model="aiFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">Any AI mode</option><option value="auto">Auto reply</option><option value="manual">Manual</option>
                </select>
                <select v-model="statusFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">All statuses</option><option value="open">Open</option><option value="closed">Closed</option>
                </select>
            </div>

            <div v-if="!conversations.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                No conversations yet. They'll appear here as soon as a contact messages a connected channel.
            </div>

            <div v-if="filteredConversations.length" class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">Contact</th>
                            <th class="px-4 py-3 font-medium">Channel</th>
                            <th class="px-4 py-3 font-medium">AI</th>
                            <th class="px-4 py-3 font-medium">Last Message</th>
                            <th class="px-4 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="c in filteredConversations" :key="c.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3 font-medium">{{ c.contact_name }}</td>
                            <td class="px-4 py-3 text-slate-500 capitalize">{{ c.channel_name }} ({{ c.channel_type }})</td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs" :class="c.is_ai_enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                    {{ c.is_ai_enabled ? 'Auto' : 'Manual' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ c.last_message_at || '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="panelRoute('chat-engine.conversations.show', { conversation: c.id })" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Open</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else-if="conversations.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">No conversations match the selected filters.</div>
        </div>
    </AuthenticatedLayout>
</template>
