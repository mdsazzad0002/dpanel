<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChatEngineDocsButton from '@/Components/ChatEngineDocsButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

defineProps({
    scheduledMessages: { type: Array, default: () => [] },
});

const cancelForm = useForm({});

const cancel = (s) => {
    if (!confirm('Cancel this scheduled message?')) return;
    cancelForm.delete(panelRoute('chat-engine.scheduled-messages.destroy', { scheduledMessage: s.id }));
};
</script>

<template>
    <Head title="Scheduled Messages" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">Scheduled Messages</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Broadcast to every contact on a channel, or schedule a single send.</p>
                </div>
                <div class="flex items-center gap-2">
                    <ChatEngineDocsButton title="Scheduled Messages — Guide" :sections="['scheduled', 'worker']" />
                    <Link :href="panelRoute('chat-engine.scheduled-messages.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">+ Schedule Message</Link>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>

            <div v-if="!scheduledMessages.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                Nothing scheduled yet.
                <Link :href="panelRoute('chat-engine.scheduled-messages.create')" class="ml-1 text-blue-600 hover:underline">Schedule your first message</Link>.
            </div>

            <div v-else class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">Channel</th>
                            <th class="px-4 py-3 font-medium">Audience</th>
                            <th class="px-4 py-3 font-medium">Message</th>
                            <th class="px-4 py-3 font-medium">Run At</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Sent / Failed</th>
                            <th class="px-4 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="s in scheduledMessages" :key="s.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3">{{ s.channel_name }}</td>
                            <td class="px-4 py-3 capitalize">{{ s.audience_type }}</td>
                            <td class="max-w-xs truncate px-4 py-3" :title="s.content">{{ s.content }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ s.run_at }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs" :class="{
                                    'bg-slate-200 text-slate-600': s.status === 'pending',
                                    'bg-emerald-100 text-emerald-700': s.status === 'dispatched',
                                    'bg-red-100 text-red-700': s.status === 'failed',
                                    'bg-amber-100 text-amber-700': s.status === 'cancelled',
                                }">{{ s.status }}</span>
                            </td>
                            <td class="px-4 py-3">{{ s.sent_count }} / {{ s.failed_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <button v-if="s.status === 'pending'" @click="cancel(s)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">Cancel</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
