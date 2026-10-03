<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ScanHistoryCard from './components/ScanHistoryCard.vue';

const props = defineProps({
    scans: { type: Object, required: true },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const hasActive = computed(() => props.scans.data.some((scan) => ['queued', 'running'].includes(scan.status)));

// Refresh while a scan on this page is queued or running.
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (hasActive.value) router.reload({ only: ['scans'], preserveScroll: true });
    }, 5000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="Scan History" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Scan History</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ scans.total }} scan(s). The latest completed scan of each type is kept, because the security score uses it.</p>
            </div>
        </template>

        <div class="space-y-4">
            <ScanHistoryCard :scans="scans.data" :panel-route="panelRoute" @deleted="router.reload({ only: ['scans'] })" />

            <div v-if="scans.last_page > 1" class="flex flex-wrap gap-2">
                <template v-for="link in scans.links" :key="link.label">
                    <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 dark:border-slate-700'"><span v-html="link.label" /></Link>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
