<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CacheSettingsCard from '@/Pages/Websites/components/edge-cache/CacheSettingsCard.vue';
import CachePurgeCard from '@/Pages/Websites/components/edge-cache/CachePurgeCard.vue';
import CacheStatsCard from '@/Pages/Websites/components/edge-cache/CacheStatsCard.vue';
import DevelopmentModeCard from '@/Pages/Websites/components/edge-cache/DevelopmentModeCard.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: { type: Object, required: true },
    isSystem: { type: Boolean, default: false },
    settings: { type: Object, required: true },
    defaults: { type: Object, required: true },
});

const { panelRoute } = usePanelApi();
const current = ref({ ...props.settings });
const statsCard = ref(null);
const url = (name) => panelRoute(name, { id: props.website.id });
</script>

<template>
    <Head title="Edge Cache" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Edge Cache</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Page caching for {{ website.domain }}, served by this server.</p>
                </div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700">Back to Manage</Link>
            </div>
        </template>

        <div class="mx-auto grid max-w-5xl gap-5">
            <div v-if="isSystem" class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                This is the panel's own website. It is never cached, so every page always shows live data.
            </div>
            <template v-else>
                <DevelopmentModeCard
                    v-if="current.mode !== 'off'"
                    :until="current.development_mode_until"
                    :toggle-url="url('websites.edge-cache.development-mode')"
                    @changed="(settings) => (current = settings)"
                />
                <CacheSettingsCard
                    :settings="current"
                    :defaults="defaults"
                    :save-url="url('websites.edge-cache.update')"
                    @saved="(settings) => { current = settings; statsCard?.load(); }"
                />
                <template v-if="current.mode !== 'off'">
                    <CacheStatsCard ref="statsCard" :domain="website.domain" :stats-url="url('websites.edge-cache.stats')" />
                    <CachePurgeCard :domain="website.domain" :purge-url="url('websites.edge-cache.purge')" @purged="statsCard?.load()" />
                </template>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
