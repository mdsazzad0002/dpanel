<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChatEngineDocsButton from '@/Components/ChatEngineDocsButton.vue';
import AppsTab from './Apps/AppsTab.vue';
import ManageTab from './Manage/ManageTab.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { usePanelRoute } from './shared.js';

const props = defineProps({
    tab: { type: String, default: 'apps' },
    business: { type: Object, required: true },
    stats: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    apps: { type: Array, default: () => [] },
    unassignedApps: { type: Array, default: () => [] },
    otherBusinesses: { type: Array, default: () => [] },
    facebookWebhookUrl: { type: String, default: '' },
});

const page = usePage();
const panelRoute = usePanelRoute();

const activeTab = ref(props.tab);
const tabs = computed(() => [
    { id: 'apps', label: 'Apps', icon: 'bi-grid-1x2', count: props.stats.apps },
    { id: 'manage', label: 'Manage', icon: 'bi-sliders', count: null },
]);

// Keep ?tab= in the address bar, so a reload or a redirect back() after any
// action (save, toggle, transfer) lands on the same tab.
const switchTab = (id) => {
    activeTab.value = id;
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    window.history.replaceState(window.history.state, '', url);
};

const onTabKey = (event) => {
    const ids = tabs.value.map((t) => t.id);
    const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
    if (!step) return;
    event.preventDefault();
    const next = ids[(ids.indexOf(activeTab.value) + step + ids.length) % ids.length];
    switchTab(next);
    document.getElementById(`business-tab-${next}`)?.focus();
};

const docsSections = computed(() => (activeTab.value === 'apps'
    ? ['business-apps', 'reply-capabilities', 'reply-flow', 'troubleshooting']
    : ['business-training', 'reply-capabilities', 'business-live-data', 'troubleshooting']));

const statTiles = computed(() => [
    { label: 'Apps live', value: `${props.stats.active_apps}/${props.stats.apps}`, icon: 'bi-broadcast' },
    { label: 'Contacts', value: props.stats.contacts.toLocaleString(), icon: 'bi-people' },
    { label: 'Conversations', value: props.stats.conversations.toLocaleString(), icon: 'bi-chat-dots' },
    { label: 'Human takeover', value: props.stats.manual_conversations.toLocaleString(), icon: 'bi-person-raised-hand' },
    {
        label: 'AI replies',
        value: props.business.ai_reply_limit !== null
            ? `${props.business.ai_replies_used.toLocaleString()} / ${props.business.ai_reply_limit.toLocaleString()}`
            : props.business.ai_replies_used.toLocaleString(),
        icon: 'bi-stars',
    },
]);
</script>

<template>
    <Head :title="business.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-violet-500 text-lg font-semibold text-white shadow-sm">
                        {{ business.name.charAt(0).toUpperCase() }}
                    </span>
                    <div class="min-w-0">
                        <nav class="flex items-center gap-1 text-xs text-slate-400" aria-label="Breadcrumb">
                            <Link :href="panelRoute('chat-engine.businesses.index')" class="hover:text-blue-600 hover:underline">Businesses</Link>
                            <i class="bi bi-chevron-right text-[10px]"></i>
                        </nav>
                        <h1 class="truncate text-lg font-semibold">{{ business.name }}</h1>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                            {{ business.industry || 'No industry set' }}<span v-if="business.owner"> · Owner: {{ business.owner.name }}</span>
                        </p>
                    </div>
                </div>
                <ChatEngineDocsButton :title="`${business.name} — Guide`" :sections="docsSections" />
            </div>
        </template>

        <div class="space-y-5">
            <transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0">
                <div v-if="page.props.flash?.success" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 shadow-sm dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">
                    <i class="bi bi-check-circle-fill"></i> {{ page.props.flash.success }}
                </div>
            </transition>
            <div v-if="page.props.flash?.error" class="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 shadow-sm dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                <i class="bi bi-exclamation-circle-fill"></i> {{ page.props.flash.error }}
            </div>

            <!-- Stats strip -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <div v-for="tile in statTiles" :key="tile.label" class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    <p class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400"><i :class="['bi', tile.icon]"></i> {{ tile.label }}</p>
                    <p class="mt-1 text-lg font-semibold text-slate-800 dark:text-slate-100">{{ tile.value }}</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-slate-200 dark:border-slate-700">
                <div class="-mb-px flex gap-6" role="tablist" aria-label="Business sections" @keydown="onTabKey">
                    <button
                        v-for="t in tabs"
                        :id="`business-tab-${t.id}`"
                        :key="t.id"
                        type="button"
                        role="tab"
                        :aria-selected="activeTab === t.id"
                        :aria-controls="`business-panel-${t.id}`"
                        :tabindex="activeTab === t.id ? 0 : -1"
                        @click="switchTab(t.id)"
                        class="inline-flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-medium transition"
                        :class="activeTab === t.id
                            ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                    >
                        <i :class="['bi', t.icon]"></i>
                        {{ t.label }}
                        <span v-if="t.count !== null" class="rounded-full px-2 py-px text-xs" :class="activeTab === t.id ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'">{{ t.count }}</span>
                    </button>
                </div>
            </div>

            <div v-show="activeTab === 'apps'" id="business-panel-apps" role="tabpanel" aria-labelledby="business-tab-apps">
                <AppsTab
                    :business="business"
                    :apps="apps"
                    :unassigned-apps="unassignedApps"
                    :other-businesses="otherBusinesses"
                    :facebook-webhook-url="facebookWebhookUrl"
                />
            </div>
            <div v-show="activeTab === 'manage'" id="business-panel-manage" role="tabpanel" aria-labelledby="business-tab-manage">
                <ManageTab :business="business" :products="products" :stats="stats" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
