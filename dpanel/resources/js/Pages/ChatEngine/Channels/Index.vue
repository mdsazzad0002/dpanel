<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import ChatEngineDocsButton from '@/Components/ChatEngineDocsButton.vue';
import ChannelForm from './ChannelForm.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    channels: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
    ownershipOptions: { type: Array, default: () => [] },
    facebookWebhookUrl: { type: String, default: '' },
});

const toggleForm = useForm({});
const deleteForm = useForm({});
const reconnectForm = useForm({});
const search = ref('');
const ownerFilter = ref('');
const typeFilter = ref('');
const statusFilter = ref('');
const filteredChannels = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return props.channels.filter((channel) => (
        (!needle || [channel.name, channel.external_account_id, channel.owner?.name, channel.owner?.email]
            .some((value) => String(value || '').toLowerCase().includes(needle)))
        && (!ownerFilter.value || String(channel.owner?.id || '') === ownerFilter.value)
        && (!typeFilter.value || channel.type === typeFilter.value)
        && (!statusFilter.value || (statusFilter.value === 'active' ? channel.is_active : !channel.is_active))
    ));
});

const toggle = (c) => {
    toggleForm.patch(panelRoute('chat-engine.channels.toggle', { channel: c.id }));
};

const remove = (c) => {
    if (!confirm(`Delete channel "${c.name}"? Its contacts and conversations will also be removed.`)) return;
    deleteForm.delete(panelRoute('chat-engine.channels.destroy', { channel: c.id }));
};

const reconnect = (c) => {
    reconnectForm.post(panelRoute('chat-engine.channels.reconnect', { channel: c.id }), { preserveScroll: true });
};
const transferOwnership = (channel, event) => {
    const ownerId = Number(event.target.value);
    if (!ownerId || ownerId === Number(channel.owner?.id)) return;
    if (!confirm(`Transfer "${channel.name}" to the selected owner?`)) {
        event.target.value = String(channel.owner?.id || '');
        return;
    }
    router.patch(panelRoute('chat-engine.channels.owner', { channel: channel.id }), { owner_id: ownerId }, { preserveScroll: true });
};

const panelOpen = ref(false);
const editingChannel = ref(null); // null = create mode

const openCreate = () => {
    editingChannel.value = null;
    panelOpen.value = true;
};

const openEdit = (c) => {
    editingChannel.value = c;
    panelOpen.value = true;
};

const closePanel = () => {
    panelOpen.value = false;
};

const panelTitle = computed(() => (editingChannel.value ? 'Edit Channel' : 'Connect Channel'));
const panelSubtitle = computed(() => (editingChannel.value ? editingChannel.value.name : 'Telegram, Facebook, WhatsApp, Instagram or Slack'));

const typeIcon = (type) => ({
    telegram: 'bi-telegram',
    facebook: 'bi-facebook',
    whatsapp: 'bi-whatsapp',
    instagram: 'bi-instagram',
    slack: 'bi-slack',
}[type] || 'bi-broadcast');
</script>

<template>
    <Head title="Chat Channels" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">Chat Channels</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Connect Telegram, Facebook and WhatsApp for AI auto-reply.</p>
                </div>
                <div class="flex items-center gap-2">
                    <ChatEngineDocsButton title="Channels — Guide" :sections="['telegram-bot', 'connect-channel', 'facebook-pages', 'whatsapp-cloud', 'instagram-dm', 'slack-app', 'website-widget']" />
                    <button @click="openCreate" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">+ Connect Channel</button>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="channels.length" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-slate-700 dark:bg-slate-800">
                <input v-model="search" type="search" placeholder="Search channel, Page ID or owner…" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <select v-model="ownerFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">All owners</option>
                    <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
                </select>
                <select v-model="typeFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">All channel types</option>
                    <option value="facebook">Facebook</option>
                    <option value="telegram">Telegram</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="instagram">Instagram</option>
                    <option value="slack">Slack</option>
                </select>
                <select v-model="statusFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div v-if="!channels.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                No channels connected yet.
                <button @click="openCreate" class="ml-1 text-blue-600 hover:underline">Connect your first channel</button>.
            </div>

            <div v-if="filteredChannels.length" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="c in filteredChannels" :key="c.id" class="flex flex-col rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-2">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-lg text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                <i :class="['bi', typeIcon(c.type)]"></i>
                            </span>
                            <div>
                                <p class="font-medium leading-tight">{{ c.name }}</p>
                                <p class="text-xs capitalize text-slate-500 dark:text-slate-400">{{ c.type }}</p>
                                <p v-if="c.owner" class="mt-0.5 text-xs text-slate-400">Owner: {{ c.owner.name }}</p>
                            </div>
                        </div>
                        <button @click="toggle(c)" class="rounded px-2 py-0.5 text-xs font-medium" :class="c.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">
                            {{ c.is_active ? 'Active' : 'Inactive' }}
                        </button>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-2 text-center text-sm">
                        <div class="rounded-md bg-slate-50 py-2 dark:bg-slate-900/40">
                            <p class="text-base font-semibold">{{ c.contacts_count }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Contacts</p>
                        </div>
                        <div class="rounded-md bg-slate-50 py-2 dark:bg-slate-900/40">
                            <p class="text-base font-semibold">{{ c.conversations_count }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Conversations</p>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-xs">
                        <span class="text-slate-500 dark:text-slate-400">Auto-reply</span>
                        <span class="rounded px-2 py-0.5 font-medium" :class="c.auto_reply_enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">
                            {{ c.auto_reply_enabled ? 'On' : 'Off' }}
                        </span>
                    </div>

                    <div v-if="ownershipOptions.length > 1" class="mt-3">
                        <label class="mb-1 block text-xs text-slate-500 dark:text-slate-400">Assign owner</label>
                        <select :value="String(c.owner?.id || '')" @change="transferOwnership(c, $event)" class="w-full rounded-md border-slate-300 py-1.5 text-xs dark:border-slate-600 dark:bg-slate-900">
                            <option v-for="owner in ownershipOptions" :key="owner.id" :value="String(owner.id)">{{ owner.name }} — {{ owner.email }}</option>
                        </select>
                    </div>

                    <div class="mt-4 flex flex-1 items-end gap-2">
                        <Link
                            :href="panelRoute('chat-engine.conversations.index', { channel_id: c.id })"
                            class="flex-1 rounded-md bg-blue-600 px-3 py-1.5 text-center text-xs font-medium text-white hover:bg-blue-700"
                        ><i class="bi bi-chat-dots"></i> Messages</Link>
                        <button @click="openEdit(c)" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Edit</button>
                        <button @click="reconnect(c)" :disabled="reconnectForm.processing" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700" title="Reconnect webhook">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        <button @click="remove(c)" class="rounded-md border border-red-300 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:border-red-700" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div v-else-if="channels.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">No channels match the selected filters.</div>
        </div>

        <Offcanvas :show="panelOpen" :title="panelTitle" :subtitle="panelSubtitle" width="wide" @close="closePanel">
            <ChannelForm
                v-if="panelOpen"
                :channel="editingChannel"
                :facebook-webhook-url="facebookWebhookUrl"
                @saved="closePanel"
                @cancel="closePanel"
            />
        </Offcanvas>
    </AuthenticatedLayout>
</template>
