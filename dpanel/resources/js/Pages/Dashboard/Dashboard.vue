<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import WelcomeHero from './components/WelcomeHero.vue';
import ResourceGauge from './components/ResourceGauge.vue';
import AccountUsage from './components/AccountUsage.vue';
import ToolCategories from './components/ToolCategories.vue';
import ServiceStatusList from './components/ServiceStatusList.vue';
import ResourceLinks from './components/ResourceLinks.vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

// Both are deferred props: undefined until their background request finishes,
// which the child components render as skeletons.
const dashboardStats = computed(() => page.props.dashboardStats ?? null);
const accountSummary = computed(() => page.props.accountSummary ?? null);
const canViewServerUsage = computed(() => page.props.canViewServerUsage === true);
const user = computed(() => page.props.auth?.user ?? {});

const percentOf = (used, total) => (Number(total) > 0 ? (Number(used || 0) / Number(total)) * 100 : 0);

const gauges = computed(() => {
    const s = dashboardStats.value;
    if (!s) {
        return [
            { label: 'CPU Usage', icon: 'bi-cpu', percent: null },
            { label: 'Memory', icon: 'bi-memory', percent: null },
            { label: 'Disk Usage', icon: 'bi-hdd', percent: null },
        ];
    }
    return [
        { label: 'CPU Usage', icon: 'bi-cpu', percent: Number(s.cpu_load_percent ?? 0), detail: `${s.cpu_cores ?? '—'} cores` },
        { label: 'Memory', icon: 'bi-memory', percent: percentOf(s.memory_used_mb, s.memory_total_mb), detail: `${s.memory_used_mb ?? 0} / ${s.memory_total_mb ?? 0} MB` },
        { label: 'Disk Usage', icon: 'bi-hdd', percent: percentOf(s.disk_used_gb, s.disk_total_gb), detail: `${s.disk_used_gb ?? 0} / ${s.disk_total_gb ?? 0} GB` },
    ];
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="sr-only">
                <h1>Dashboard</h1>
            </div>
        </template>

        <div class="-mt-6 min-w-0 space-y-4 sm:space-y-6">
            <WelcomeHero :stats="dashboardStats" :user-name="user.name ?? ''" :user-email="user.email ?? ''" />

            <section v-if="canViewServerUsage" aria-label="Server resources" class="grid grid-cols-1 gap-4 sm:grid-cols-3 md:grid-cols-1 lg:grid-cols-3">
                <ResourceGauge v-for="gauge in gauges" :key="gauge.label" v-bind="gauge" />
            </section>

            <!--
                Below xl: one column ordered usage → tools → services → links.
                xl+: tools/services on the left, usage/links in a right rail.
                Breakpoints are viewport-based and the sidebar takes 288px from md up,
                so the two-column split waits for xl. The column wrappers are
                `display: contents` below xl so `order` applies across them.
            -->
            <div class="flex flex-col gap-6 xl:grid xl:grid-cols-12 xl:items-start">
                <div class="contents xl:col-span-8 xl:flex xl:min-w-0 xl:flex-col xl:gap-6">
                    <section class="order-2 min-w-0 xl:order-none">
                        <h2 class="mb-3 font-mono text-xs uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400">Quick actions</h2>
                        <ToolCategories
                            :roles="page.props.auth?.roles ?? []"
                            :permissions="page.props.auth?.permissions ?? []"
                            :panel-route="panelRoute"
                        />
                    </section>
                    <ServiceStatusList class="order-3 xl:order-none" :services="dashboardStats?.services ?? null" />
                </div>

                <div class="contents xl:col-span-4 xl:flex xl:min-w-0 xl:flex-col xl:gap-6">
                    <AccountUsage class="order-1 xl:order-none" :summary="accountSummary" />
                    <ResourceLinks class="order-4 xl:order-none" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
