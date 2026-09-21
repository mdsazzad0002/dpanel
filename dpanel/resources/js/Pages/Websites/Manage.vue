<script setup>
import { computed, provide, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import ToastStack from '@/Pages/Websites/components/ToastStack.vue';
import WebsiteHeroCard from '@/Pages/Websites/components/WebsiteHeroCard.vue';
import QuickActionsPanel from '@/Pages/Websites/components/QuickActionsPanel.vue';
import NodeServicePanel from '@/Pages/Websites/components/NodeServicePanel.vue';
import PythonServicePanel from '@/Pages/Websites/components/PythonServicePanel.vue';
import MetricsGrid from '@/Pages/Websites/components/MetricsGrid.vue';
import ServicesGrid from '@/Pages/Websites/components/ServicesGrid.vue';
import ActivityTimeline from '@/Pages/Websites/components/ActivityTimeline.vue';
import RuntimeSettingsModal from '@/Pages/Websites/components/RuntimeSettingsModal.vue';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    metrics: {
        type: Object,
        default: () => ({}),
    },
    activities: {
        type: Array,
        default: () => [],
    },
    sslStatus: {
        type: Object,
        default: () => ({}),
    },
    autoRenewNotice: {
        type: String,
        default: '',
    },
    rootInspection: {
        type: Object,
        default: () => ({}),
    },
    databaseConnection: { type: Object, default: () => ({ available: false }) },
    phpVersions: { type: Array, default: () => [] },
});

const page = usePage();

const toasts = ref([]);
let toastSeq = 0;

const removeToast = (id) => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
};

const pushToast = (message, type = 'error') => {
    if (!message) return;

    const id = `${Date.now()}-${toastSeq += 1}`;
    toasts.value.push({
        id,
        message: String(message),
        type,
    });

    window.setTimeout(() => {
        removeToast(id);
    }, 3500);
};

// Every section component below injects this instead of receiving it as a
// prop, so adding a new section never means threading a callback through
// Manage.vue just to let it show a toast.
provide('pushToast', pushToast);

const editingRuntimeSettings = ref(false);

const isNodeWebsite = computed(() => String(props.website?.runtime || 'php') === 'node');
const isPythonWebsite = computed(() => String(props.website?.runtime || 'php') === 'python');
</script>

<template>
    <Head title="Manage Website" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Website Management</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Tools and configuration for {{ website.domain }}.
                </p>
            </div>
        </template>

        <div class="space-y-6">
            <ToastStack :toasts="toasts" @dismiss="removeToast" />

            <!-- Flash Messages -->
            <div v-if="page.props.flash?.success"
                class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" />
                </svg>
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error"
                class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current">
                    <path
                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                </svg>
                {{ page.props.flash.error }}
            </div>

            <!-- Hero Section -->
            <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50 lg:p-8">
                <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(380px,.9fr)]">
                    <WebsiteHeroCard
                        :website="website"
                        :ssl-status="sslStatus"
                        :root-inspection="rootInspection"
                        :database-connection="databaseConnection"
                        @edit-runtime="editingRuntimeSettings = true"
                    />
                    <QuickActionsPanel
                        :website="website"
                        :root-inspection="rootInspection"
                        :database-connection="databaseConnection"
                    />
                </div>
            </section>

            <NodeServicePanel v-if="isNodeWebsite" :website="website" />
            <PythonServicePanel v-if="isPythonWebsite" :website="website" />

            <!-- Services + Activity -->
            <section class="grid gap-4 xl:grid-cols-[minmax(0,2.4fr)_minmax(300px,1fr)]">
                <div class="contents">
                    <MetricsGrid :website="website" :metrics="metrics" />
                    <ServicesGrid :website="website" />
                </div>

                <ActivityTimeline :activities="activities" />
            </section>
        </div>

        <RuntimeSettingsModal v-model="editingRuntimeSettings" :website="website" :php-versions="phpVersions" />
    </AuthenticatedLayout>
</template>
