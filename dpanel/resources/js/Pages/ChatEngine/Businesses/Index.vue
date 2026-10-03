<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChatEngineDocsButton from '@/Components/ChatEngineDocsButton.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { appType, usePanelRoute } from './shared.js';

const page = usePage();
const panelRoute = usePanelRoute();

const props = defineProps({
    businesses: { type: Array, default: () => [] },
});

const search = ref('');
const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.businesses.filter((b) => !needle
        || [b.name, b.industry, b.owner?.name, b.owner?.email].some((v) => String(v || '').toLowerCase().includes(needle)));
});

const usagePercent = (b) => (b.ai_reply_limit ? Math.min(100, (b.ai_replies_used / Math.max(b.ai_reply_limit, 1)) * 100) : 0);
</script>

<template>
    <Head title="Businesses" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-lg font-semibold">Businesses</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Each business owns its apps (chat channels) and the knowledge the AI answers them with.</p>
                </div>
                <div class="flex items-center gap-2">
                    <ChatEngineDocsButton title="Businesses — Guide" :sections="['business-apps', 'business-training', 'reply-capabilities', 'business-live-data']" />
                    <Link :href="panelRoute('chat-engine.businesses.create')" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                        <i class="bi bi-plus-lg"></i> Create Business
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ page.props.flash.error }}</div>

            <div v-if="!businesses.length" class="rounded-xl border-2 border-dashed border-slate-300 bg-white px-6 py-14 text-center dark:border-slate-700 dark:bg-slate-800/50">
                <span class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-blue-100 text-xl text-blue-600 dark:bg-blue-900/40 dark:text-blue-300"><i class="bi bi-briefcase"></i></span>
                <h3 class="font-semibold text-slate-800 dark:text-slate-100">Start with a business</h3>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">Create a business, tell the AI what it sells and how to answer, then connect its apps — Telegram, Facebook, WhatsApp, Instagram, Slack or a website widget.</p>
                <Link :href="panelRoute('chat-engine.businesses.create')" class="mt-5 inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                    <i class="bi bi-plus-lg"></i> Create your first business
                </Link>
            </div>

            <template v-else>
                <input v-if="businesses.length > 3" v-model="search" type="search" placeholder="Search business, industry or owner…" class="w-full rounded-lg border-slate-300 text-sm sm:max-w-sm dark:border-slate-600 dark:bg-slate-900" />

                <div v-if="filtered.length" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="b in filtered" :key="b.id" class="flex flex-col rounded-xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md dark:border-slate-700 dark:bg-slate-800">
                        <Link :href="panelRoute('chat-engine.businesses.edit', { business: b.id, tab: 'apps' })" class="flex items-start gap-3 p-4">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-violet-500 font-semibold text-white">{{ b.name.charAt(0).toUpperCase() }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold leading-tight text-slate-800 dark:text-slate-100">{{ b.name }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ b.industry || 'No industry set' }}<span v-if="b.owner"> · {{ b.owner.name }}</span></p>
                            </div>
                        </Link>

                        <div class="flex items-center gap-1.5 px-4">
                            <span
                                v-for="type in b.app_types"
                                :key="type"
                                class="flex h-7 w-7 items-center justify-center rounded-md text-sm"
                                :class="appType(type).tint"
                                :title="appType(type).label"
                            ><i :class="['bi', appType(type).icon]"></i></span>
                            <span v-if="!b.app_types.length" class="text-xs text-slate-400">No apps connected yet</span>
                        </div>

                        <div class="mt-4 grid grid-cols-3 gap-px border-y border-slate-100 bg-slate-100 text-center dark:border-slate-700 dark:bg-slate-700">
                            <div class="bg-white py-2.5 dark:bg-slate-800">
                                <p class="text-sm font-semibold">{{ b.active_channels_count }}<span class="font-normal text-slate-400">/{{ b.channels_count }}</span></p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">Apps live</p>
                            </div>
                            <div class="bg-white py-2.5 dark:bg-slate-800">
                                <p class="text-sm font-semibold">{{ b.products_count }}</p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">Products</p>
                            </div>
                            <div class="bg-white py-2.5 dark:bg-slate-800">
                                <p class="text-sm font-semibold">{{ b.ai_replies_used.toLocaleString() }}</p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">AI replies</p>
                            </div>
                        </div>

                        <div v-if="b.ai_reply_limit !== null" class="px-4 pt-3">
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                                <div class="h-full rounded-full" :class="usagePercent(b) >= 100 ? 'bg-red-500' : 'bg-violet-500'" :style="{ width: usagePercent(b) + '%' }"></div>
                            </div>
                            <p class="mt-1 text-[11px] text-slate-400">{{ b.ai_replies_used.toLocaleString() }} of {{ b.ai_reply_limit.toLocaleString() }} reply credit used</p>
                        </div>

                        <div class="mt-auto flex gap-2 p-4">
                            <Link :href="panelRoute('chat-engine.businesses.edit', { business: b.id, tab: 'apps' })" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-blue-700">
                                <i class="bi bi-grid-1x2"></i> Apps
                            </Link>
                            <Link :href="panelRoute('chat-engine.businesses.edit', { business: b.id, tab: 'manage' })" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">
                                <i class="bi bi-sliders"></i> Manage
                            </Link>
                        </div>
                    </div>
                </div>
                <div v-else class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">No business matches “{{ search }}”.</div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
