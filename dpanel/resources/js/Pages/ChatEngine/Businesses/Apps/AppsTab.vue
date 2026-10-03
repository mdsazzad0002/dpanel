<script setup>
import Offcanvas from '@/Components/Offcanvas.vue';
import ChannelForm from '@/Pages/ChatEngine/Channels/ChannelForm.vue';
import AppCard from './AppCard.vue';
import TransferAppsPanel from './TransferAppsPanel.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { APP_TYPES, appType, usePanelRoute } from '../shared.js';

const props = defineProps({
    business: { type: Object, required: true },
    apps: { type: Array, default: () => [] },
    unassignedApps: { type: Array, default: () => [] },
    otherBusinesses: { type: Array, default: () => [] },
    facebookWebhookUrl: { type: String, default: '' },
});

const panelRoute = usePanelRoute();

// --- Filters ---
const search = ref('');
const typeFilter = ref('');
const statusFilter = ref('');

const typeCounts = computed(() => props.apps.reduce((acc, a) => ({ ...acc, [a.type]: (acc[a.type] || 0) + 1 }), {}));

const filteredApps = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.apps.filter((a) => (
        (!needle || [a.name, a.external_account_id, a.type].some((v) => String(v || '').toLowerCase().includes(needle)))
        && (!typeFilter.value || a.type === typeFilter.value)
        && (!statusFilter.value || (statusFilter.value === 'live' ? a.is_active : !a.is_active))
    ));
});

// --- Selection (bulk transfer / detach) ---
const selectedIds = ref([]);
// Drop selections that disappeared after a reload (moved, deleted).
watch(() => props.apps, (apps) => {
    const ids = new Set(apps.map((a) => a.id));
    selectedIds.value = selectedIds.value.filter((id) => ids.has(id));
});

const toggleSelect = (id) => {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((x) => x !== id)
        : [...selectedIds.value, id];
};
const allFilteredSelected = computed(() => filteredApps.value.length > 0 && filteredApps.value.every((a) => selectedIds.value.includes(a.id)));
const toggleSelectAll = () => {
    const filteredIds = filteredApps.value.map((a) => a.id);
    selectedIds.value = allFilteredSelected.value
        ? selectedIds.value.filter((id) => !filteredIds.includes(id))
        : Array.from(new Set([...selectedIds.value, ...filteredIds]));
};
const selectedApps = computed(() => props.apps.filter((a) => selectedIds.value.includes(a.id)));

// --- Side panels: connect/edit app, transfer/detach ---
const panel = ref(null); // { kind: 'form', app } | { kind: 'transfer'|'detach', apps }
const closePanel = () => { panel.value = null; };

const openConnect = () => { panel.value = { kind: 'form', app: null }; };
const openEdit = (app) => { panel.value = { kind: 'form', app }; };
const openTransfer = (apps) => { panel.value = { kind: 'transfer', apps }; };
const openDetach = (apps) => { panel.value = { kind: 'detach', apps }; };

const panelTitle = computed(() => ({
    form: panel.value?.app ? 'Edit app' : 'Connect new app',
    transfer: 'Transfer to another business',
    detach: 'Detach from business',
}[panel.value?.kind] || ''));
const panelSubtitle = computed(() => (panel.value?.kind === 'form'
    ? (panel.value.app ? panel.value.app.name : `Answers with ${props.business.name}'s knowledge`)
    : props.business.name));

const afterBulk = () => {
    selectedIds.value = [];
    closePanel();
};

// --- Attach an existing, unassigned app ---
const attachId = ref('');
const attaching = ref(false);
const attachError = ref('');
const attach = async () => {
    if (!attachId.value) return;
    attaching.value = true;
    attachError.value = '';
    try {
        await axios.post(panelRoute('chat-engine.businesses.channels.assign', { business: props.business.id }), { channel_id: attachId.value });
        attachId.value = '';
        router.reload({ only: ['apps', 'unassignedApps', 'stats'], preserveScroll: true });
    } catch (e) {
        attachError.value = e.response?.data?.message || 'Could not attach that app.';
    } finally {
        attaching.value = false;
    }
};
</script>

<template>
    <div class="space-y-4">
        <!-- Action bar -->
        <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm lg:flex-row lg:items-center lg:justify-between dark:border-slate-700 dark:bg-slate-800">
            <div class="flex flex-wrap items-center gap-2">
                <Link :href="panelRoute('chat-engine.conversations.index', { business_id: business.id })" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-2 text-sm transition hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">
                    <i class="bi bi-inbox"></i> Inbox
                </Link>
                <Link :href="panelRoute('chat-engine.scheduled-messages.index', { business_id: business.id })" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-2 text-sm transition hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">
                    <i class="bi bi-calendar2-event"></i> Scheduled
                </Link>
                <Link :href="panelRoute('chat-engine.facebook-posts.index')" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-2 text-sm transition hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">
                    <i class="bi bi-file-post"></i> Facebook posts
                </Link>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div v-if="unassignedApps.length" class="flex items-center gap-2">
                    <select v-model="attachId" class="min-w-0 rounded-md border-slate-300 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" aria-label="Attach an existing app">
                        <option value="">Attach existing app…</option>
                        <option v-for="a in unassignedApps" :key="a.id" :value="a.id">{{ a.name }} ({{ appType(a.type).label }})</option>
                    </select>
                    <button type="button" @click="attach" :disabled="!attachId || attaching" class="rounded-md border border-blue-300 px-3 py-2 text-sm font-medium text-blue-700 transition hover:bg-blue-50 disabled:opacity-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/30">
                        {{ attaching ? 'Attaching…' : 'Attach' }}
                    </button>
                </div>
                <button type="button" @click="openConnect" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700">
                    <i class="bi bi-plus-lg"></i> Connect new app
                </button>
            </div>
        </div>
        <p v-if="attachError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ attachError }}</p>

        <!-- Empty state -->
        <div v-if="!apps.length" class="rounded-xl border-2 border-dashed border-slate-300 bg-white px-6 py-12 text-center dark:border-slate-700 dark:bg-slate-800/50">
            <div class="mx-auto mb-4 flex w-fit gap-2">
                <span v-for="(meta, key) in APP_TYPES" :key="key" class="flex h-9 w-9 items-center justify-center rounded-lg" :class="meta.tint"><i :class="['bi', meta.icon]"></i></span>
            </div>
            <h3 class="font-semibold text-slate-800 dark:text-slate-100">No apps connected to {{ business.name }} yet</h3>
            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                Connect a Telegram bot, Facebook Page, WhatsApp number, Instagram account, Slack workspace or website widget. Every message it receives is answered with this business's knowledge.
            </p>
            <button type="button" @click="openConnect" class="mt-5 inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                <i class="bi bi-plus-lg"></i> Connect your first app
            </button>
        </div>

        <template v-else>
            <!-- Filters -->
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <label class="flex shrink-0 cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" :checked="allFilteredSelected" @change="toggleSelectAll" class="rounded border-slate-300 text-blue-600 dark:border-slate-600 dark:bg-slate-900" />
                    Select all
                </label>
                <div class="flex flex-1 flex-wrap items-center gap-1.5">
                    <button type="button" @click="typeFilter = ''" class="rounded-full px-3 py-1 text-xs font-medium transition" :class="!typeFilter ? 'bg-slate-800 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300'">
                        All · {{ apps.length }}
                    </button>
                    <button
                        v-for="(count, type) in typeCounts"
                        :key="type"
                        type="button"
                        @click="typeFilter = typeFilter === type ? '' : type"
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition"
                        :class="typeFilter === type ? 'bg-slate-800 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300'"
                    >
                        <i :class="['bi', appType(type).icon]"></i> {{ appType(type).label }} · {{ count }}
                    </button>
                </div>
                <div class="flex gap-2">
                    <input v-model="search" type="search" placeholder="Search apps…" class="w-full rounded-md border-slate-300 py-1.5 text-sm lg:w-56 dark:border-slate-600 dark:bg-slate-900" />
                    <select v-model="statusFilter" class="rounded-md border-slate-300 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" aria-label="Status">
                        <option value="">Any status</option>
                        <option value="live">Live</option>
                        <option value="paused">Paused</option>
                    </select>
                </div>
            </div>

            <!-- Bulk bar -->
            <transition enter-active-class="transition duration-150" enter-from-class="opacity-0 -translate-y-1" leave-active-class="transition duration-100" leave-to-class="opacity-0">
                <div v-if="selectedIds.length" class="sticky top-2 z-10 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-blue-200 bg-blue-50/95 px-4 py-2.5 text-sm shadow-sm backdrop-blur dark:border-blue-800 dark:bg-blue-950/90">
                    <span class="font-medium text-blue-800 dark:text-blue-200">{{ selectedIds.length }} selected</span>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="openTransfer(selectedApps)" :disabled="!otherBusinesses.length" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50" :title="otherBusinesses.length ? '' : 'Create another business first'">
                            <i class="bi bi-arrow-left-right"></i> Transfer
                        </button>
                        <button type="button" @click="openDetach(selectedApps)" class="inline-flex items-center gap-1.5 rounded-md border border-amber-400 px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-900/30">
                            <i class="bi bi-box-arrow-up-right"></i> Detach
                        </button>
                        <button type="button" @click="selectedIds = []" class="rounded-md px-3 py-1.5 text-xs text-slate-600 hover:bg-white/60 dark:text-slate-300 dark:hover:bg-slate-800">Clear</button>
                    </div>
                </div>
            </transition>

            <div v-if="filteredApps.length" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <AppCard
                    v-for="app in filteredApps"
                    :key="app.id"
                    :app="app"
                    :business-id="business.id"
                    :selected="selectedIds.includes(app.id)"
                    :can-transfer="otherBusinesses.length > 0"
                    @toggle-select="toggleSelect"
                    @edit="openEdit"
                    @transfer="(a) => openTransfer([a])"
                    @detach="(a) => openDetach([a])"
                />
            </div>
            <div v-else class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">No apps match these filters.</div>
        </template>

        <Offcanvas :show="!!panel" :title="panelTitle" :subtitle="panelSubtitle" :width="panel?.kind === 'form' ? 'wide' : 'md'" @close="closePanel">
            <ChannelForm
                v-if="panel?.kind === 'form'"
                :key="panel.app?.id || 'new'"
                :channel="panel.app"
                :business="business"
                :facebook-webhook-url="facebookWebhookUrl"
                @saved="closePanel"
                @cancel="closePanel"
            />
            <TransferAppsPanel
                v-else-if="panel"
                :business="business"
                :apps="panel.apps"
                :other-businesses="otherBusinesses"
                :mode="panel.kind"
                @done="afterBulk"
                @cancel="closePanel"
            />
        </Offcanvas>
    </div>
</template>
