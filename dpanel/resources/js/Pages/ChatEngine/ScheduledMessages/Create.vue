<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    channels: { type: Array, default: () => [] },
});

const form = useForm({
    chat_channel_id: props.channels[0]?.id || '',
    audience_type: 'broadcast',
    chat_contact_id: '',
    content: '',
    run_at: '',
});
</script>

<template>
    <Head title="Schedule Message" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Schedule Message</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Broadcast to every contact on a channel, or target one contact.</p>
            </div>
        </template>

        <div class="max-w-2xl space-y-4">
            <div v-if="!channels.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                Connect a channel first.
            </div>

            <form v-else @submit.prevent="form.post(panelRoute('chat-engine.scheduled-messages.store'))" class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <label class="mb-1 block text-sm font-medium">Channel</label>
                    <select v-model="form.chat_channel_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }} ({{ c.type }})</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Audience</label>
                    <select v-model="form.audience_type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="broadcast">Broadcast to all contacts</option>
                        <option value="contact">Single contact</option>
                    </select>
                </div>

                <div v-if="form.audience_type === 'contact'">
                    <label class="mb-1 block text-sm font-medium">Contact ID</label>
                    <input v-model="form.chat_contact_id" type="text" placeholder="Contact UUID" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono dark:border-slate-600 dark:bg-slate-900" />
                    <p v-if="form.errors.chat_contact_id" class="mt-1 text-xs text-red-600">{{ form.errors.chat_contact_id }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Message</label>
                    <textarea v-model="form.content" rows="4" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                    <p v-if="form.errors.content" class="mt-1 text-xs text-red-600">{{ form.errors.content }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Run At</label>
                    <input v-model="form.run_at" type="datetime-local" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <p v-if="form.errors.run_at" class="mt-1 text-xs text-red-600">{{ form.errors.run_at }}</p>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Schedule</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
