<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { buildPanelMenu, filterPanelMenu, resolveItemRouteName } from '@/navigation/panelMenu';
import { useDashboardLayout } from './composables/useDashboardLayout';
import DashboardHeader from './components/DashboardHeader.vue';
import SectionCustomizer from './components/SectionCustomizer.vue';
import ToolSection from './components/ToolSection.vue';
import OverviewCard from './components/OverviewCard.vue';
import AccountUsage from './components/AccountUsage.vue';
import ServiceStatusList from './components/ServiceStatusList.vue';

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
const roles = computed(() => page.props.auth?.roles ?? []);

const looseSectionIcons = {
    Hosting: 'bi bi-hdd-stack',
    'Data & Backups': 'bi bi-archive',
    Security: 'bi bi-shield',
    System: 'bi bi-gear',
};

// Tool sections come straight from the sidebar menu: every group becomes a card,
// and stand-alone links are gathered into one card per sidebar section heading.
const sections = computed(() => {
    const menu = filterPanelMenu(buildPanelMenu(roles.value), {
        roles: roles.value,
        permissions: page.props.auth?.permissions ?? [],
        features: page.props.features ?? {},
    });
    const result = [];
    const loose = new Map();

    for (const item of menu) {
        if (item.routeName === 'dashboard') continue;
        if (item.children) {
            result.push({ id: item.id, label: item.label, iconClass: item.iconClass, color: item.color, items: item.children });
            continue;
        }
        const name = item.section ?? 'General';
        if (!loose.has(name)) {
            const card = { id: `section:${name}`, label: name, iconClass: looseSectionIcons[name] ?? 'bi bi-grid', color: item.color, items: [] };
            loose.set(name, card);
            result.push(card);
        }
        loose.get(name).items.push(item);
    }

    return result
        .map((section) => ({
            ...section,
            items: section.items
                .map((item) => ({ ...item, routeName: resolveItemRouteName(item) }))
                .filter((item) => item.routeName)
                .map((item) => ({ ...item, href: panelRoute(item.routeName, item.routeParams ?? {}) })),
        }))
        .filter((section) => section.items.length);
});

const layout = useDashboardLayout(sections, `dpanel.dashboard.layout.v1.${user.value.id ?? 'guest'}`);

const draggingId = ref(null);
const dropTargetId = ref(null);
const onDrop = (targetId) => {
    if (draggingId.value) layout.moveTo(draggingId.value, targetId);
    draggingId.value = null;
    dropTargetId.value = null;
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <DashboardHeader :user-name="user.name ?? ''" />
        </template>

        <!--
            Below xl: one column ordered overview → tools → statistics → services.
            xl+: tool sections on the left, overview cards in a right rail.
            Breakpoints are viewport-based and the sidebar takes 288px from md up, so
            the split waits for xl. Column wrappers are `display: contents` below xl
            so `order` applies across them.
        -->
        <div class="flex min-w-0 flex-col gap-4 xl:grid xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start xl:gap-5 2xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="contents xl:flex xl:min-w-0 xl:flex-col xl:gap-3">
                <div class="order-2 flex min-w-0 flex-col gap-3 xl:order-none">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-mono text-xs uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400">Tools</h2>
                        <SectionCustomizer
                            :sections="layout.ordered.value"
                            :is-hidden="layout.isHidden"
                            :all-collapsed="layout.allCollapsed.value"
                            @set-hidden="layout.setHidden"
                            @collapse-all="layout.setAllCollapsed"
                            @reset="layout.reset"
                        />
                    </div>

                    <TransitionGroup tag="div" move-class="transition-transform duration-300" class="flex flex-col gap-3">
                        <ToolSection
                            v-for="(section, index) in layout.visible.value"
                            :key="section.id"
                            :section="section"
                            :collapsed="layout.isCollapsed(section.id)"
                            :dragging="draggingId === section.id"
                            :drop-target="draggingId !== null && dropTargetId === section.id && draggingId !== section.id"
                            :is-first="index === 0"
                            :is-last="index === layout.visible.value.length - 1"
                            @toggle="layout.toggleCollapsed(section.id)"
                            @move="(delta) => layout.moveBy(section.id, delta)"
                            @hide="layout.setHidden(section.id, true)"
                            @drag-start="draggingId = section.id"
                            @drag-end="draggingId = null; dropTargetId = null"
                            @drag-over="dropTargetId = section.id"
                            @drop="onDrop(section.id)"
                        />
                    </TransitionGroup>

                    <div v-if="!layout.visible.value.length" class="rounded-2xl border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-white/10 dark:text-slate-400">
                        All sections are hidden. Use <span class="font-semibold">Customize</span> to bring them back.
                    </div>
                </div>
            </div>

            <div class="contents xl:flex xl:min-w-0 xl:flex-col xl:gap-4">
                <OverviewCard
                    class="order-1 xl:order-none"
                    :stats="dashboardStats"
                    :summary="accountSummary"
                    :user="user"
                    :roles="roles"
                    :show-server="canViewServerUsage"
                />
                <AccountUsage class="order-3 xl:order-none" :summary="accountSummary" />
                <ServiceStatusList class="order-4 xl:order-none" :services="dashboardStats?.services ?? null" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
