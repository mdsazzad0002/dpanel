<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { appType, timeAgo, usePanelRoute } from '../shared.js';

const props = defineProps({
    app: { type: Object, required: true },
    businessId: { type: String, required: true },
    selected: { type: Boolean, default: false },
    canTransfer: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-select', 'edit', 'transfer', 'detach']);

const panelRoute = usePanelRoute();
const meta = computed(() => appType(props.app.type));
const busy = ref('');
const menuOpen = ref(false);

const run = (key, fn) => {
    busy.value = key;
    menuOpen.value = false;
    fn(() => { busy.value = ''; });
};

const toggleActive = () => run('toggle', (done) => router.patch(
    panelRoute('chat-engine.channels.toggle', { channel: props.app.id }), {}, { preserveScroll: true, onFinish: done },
));

const reconnect = () => run('reconnect', (done) => router.post(
    panelRoute('chat-engine.channels.reconnect', { channel: props.app.id }), {}, { preserveScroll: true, onFinish: done },
));

const remove = () => {
    if (!confirm(`Delete app "${props.app.name}"? Its contacts, conversations and scheduled messages are removed too. This cannot be undone.`)) return;
    run('delete', (done) => router.delete(
        panelRoute('chat-engine.channels.destroy', { channel: props.app.id, from_business: 1 }), { preserveScroll: true, onFinish: done },
    ));
};

const needsWebhook = computed(() => props.app.type !== 'website');
// The in-panel Assistant chats through a website app (staff-only ones also
// unlock the internal tools) — a quick way to test what the AI replies.
const canAssistant = computed(() => props.app.type === 'website');
const lastActive = computed(() => timeAgo(props.app.last_message_at));
</script>

<template>
    <div
        class="group relative flex flex-col rounded-xl border bg-white shadow-sm transition hover:shadow-md dark:bg-slate-800"
        :class="selected ? 'border-blue-500 ring-2 ring-blue-500/20' : 'border-slate-200 dark:border-slate-700'"
    >
        <div class="flex items-start gap-3 p-4">
            <label class="mt-1 flex shrink-0 cursor-pointer items-center" :title="selected ? 'Deselect' : 'Select'">
                <input type="checkbox" :checked="selected" @change="emit('toggle-select', app.id)" class="rounded border-slate-300 text-blue-600 dark:border-slate-600 dark:bg-slate-900" />
            </label>

            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-lg" :class="meta.tint">
                <i :class="['bi', meta.icon]"></i>
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold leading-tight text-slate-800 dark:text-slate-100" :title="app.name">{{ app.name }}</p>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                    <span>{{ meta.label }}</span>
                    <span v-if="app.external_account_id" class="truncate font-mono" :title="app.external_account_id">· {{ app.external_account_id }}</span>
                    <span v-if="app.internal_access" class="rounded bg-emerald-100 px-1.5 py-px text-[10px] font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">Staff-only</span>
                </p>
            </div>

            <button
                type="button"
                @click="toggleActive"
                :disabled="busy === 'toggle'"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition disabled:opacity-50"
                :class="app.is_active ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-200 text-slate-600 hover:bg-slate-300 dark:bg-slate-700 dark:text-slate-300'"
                :title="app.is_active ? 'Click to deactivate' : 'Click to activate'"
            >
                <span class="h-1.5 w-1.5 rounded-full" :class="app.is_active ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                {{ app.is_active ? 'Live' : 'Paused' }}
            </button>
        </div>

        <div class="grid grid-cols-3 gap-px border-y border-slate-100 bg-slate-100 text-center dark:border-slate-700 dark:bg-slate-700">
            <div class="bg-white px-2 py-2.5 dark:bg-slate-800">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ (app.contacts_count ?? 0).toLocaleString() }}</p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400">Contacts</p>
            </div>
            <div class="bg-white px-2 py-2.5 dark:bg-slate-800">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ (app.conversations_count ?? 0).toLocaleString() }}</p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400">Chats</p>
            </div>
            <div class="bg-white px-2 py-2.5 dark:bg-slate-800">
                <p class="text-sm font-semibold" :class="app.auto_reply_enabled ? 'text-violet-600 dark:text-violet-300' : 'text-slate-400'">{{ app.auto_reply_enabled ? 'On' : 'Off' }}</p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400">AI reply</p>
            </div>
        </div>

        <div class="flex items-center justify-between px-4 py-2 text-[11px] text-slate-400">
            <span>{{ lastActive ? `Last message ${lastActive}` : 'No messages yet' }}</span>
            <span v-if="app.owner" class="truncate pl-2" :title="app.owner.email">{{ app.owner.name }}</span>
        </div>

        <div class="mt-auto flex items-center gap-2 border-t border-slate-100 p-3 dark:border-slate-700">
            <Link
                :href="panelRoute('chat-engine.conversations.index', { channel_id: app.id })"
                class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-blue-700"
            ><i class="bi bi-chat-dots"></i> Messages</Link>
            <a
                v-if="canAssistant"
                :href="panelRoute('chat-engine.channels.assistant', { channel: app.id })"
                class="inline-flex items-center gap-1.5 rounded-md border border-violet-300 px-3 py-1.5 text-xs font-medium text-violet-700 transition hover:bg-violet-50 dark:border-violet-700 dark:text-violet-300 dark:hover:bg-violet-900/30"
            ><i class="bi bi-stars"></i> Assistant</a>
            <button type="button" @click="emit('edit', app)" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium transition hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Edit</button>

            <div class="relative">
                <button
                    type="button"
                    @click="menuOpen = !menuOpen"
                    class="rounded-md border border-slate-300 px-2 py-1.5 text-xs transition hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700"
                    aria-label="More actions"
                    :aria-expanded="menuOpen"
                ><i class="bi bi-three-dots"></i></button>

                <div v-if="menuOpen" class="fixed inset-0 z-10" @click="menuOpen = false"></div>
                <div v-if="menuOpen" class="absolute bottom-full right-0 z-20 mb-1 w-52 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-800">
                    <Link :href="panelRoute('chat-engine.scheduled-messages.create', { channel_id: app.id })" class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-700/60">
                        <i class="bi bi-calendar2-event text-slate-400"></i> Schedule message
                    </Link>
                    <button v-if="needsWebhook" type="button" @click="reconnect" :disabled="busy === 'reconnect'" class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50 disabled:opacity-50 dark:hover:bg-slate-700/60">
                        <i class="bi bi-arrow-repeat text-slate-400"></i> Reconnect webhook
                    </button>
                    <button type="button" @click="menuOpen = false; emit('transfer', app)" :disabled="!canTransfer" class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-slate-700/60" :title="canTransfer ? '' : 'Create another business first'">
                        <i class="bi bi-arrow-left-right text-slate-400"></i> Transfer to business…
                    </button>
                    <button type="button" @click="menuOpen = false; emit('detach', app)" class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-700/60">
                        <i class="bi bi-box-arrow-up-right text-slate-400"></i> Detach from business
                    </button>
                    <div class="my-1 border-t border-slate-100 dark:border-slate-700"></div>
                    <button type="button" @click="remove" :disabled="busy === 'delete'" class="flex w-full items-center gap-2 px-3 py-2 text-left text-red-600 hover:bg-red-50 disabled:opacity-50 dark:text-red-400 dark:hover:bg-red-900/20">
                        <i class="bi bi-trash"></i> Delete app
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
