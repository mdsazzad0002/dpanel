<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import DashboardHeader from './components/DashboardHeader.vue';
import StatStrip from './components/StatStrip.vue';
import ToolCategories from './components/ToolCategories.vue';
import ServiceStatusList from './components/ServiceStatusList.vue';
import AccountUsage from './components/AccountUsage.vue';

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
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <DashboardHeader :stats="dashboardStats" :user-name="page.props.auth?.user?.name ?? ''" />
        </template>

        <div class="min-w-0 space-y-4 sm:space-y-5">
            <StatStrip :stats="dashboardStats" :summary="accountSummary" :show-server="canViewServerUsage" />

            <ToolCategories
                :roles="page.props.auth?.roles ?? []"
                :permissions="page.props.auth?.permissions ?? []"
                :panel-route="panelRoute"
            />

            <div class="grid grid-cols-1 items-start gap-4 sm:gap-5 lg:grid-cols-2">
                <ServiceStatusList :services="dashboardStats?.services ?? null" />
                <AccountUsage :summary="accountSummary" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
