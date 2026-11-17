<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    active: { type: String, required: true },
    // Shown as an extra tab while one account is open.
    account: { type: Object, default: null },
});

const { panelRoute } = usePanelApi();
const tabs = computed(() => [
    { id: 'accounts', label: t('ftp.tabs.accounts'), icon: 'bi-list-ul', route: 'ftp.index' },
    { id: 'create', label: t('ftp.tabs.create'), icon: 'bi-person-plus', route: 'ftp.create' },
    { id: 'connect', label: t('ftp.tabs.connect'), icon: 'bi-plug', route: 'ftp.connect' },
]);
const tabClass = (isActive) => isActive
    ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
    : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200';
</script>

<template>
    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
        <Link
            v-for="tab in tabs"
            :key="tab.id"
            :href="panelRoute(tab.route)"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition"
            :class="tabClass(active === tab.id)"
        >
            <i class="bi" :class="tab.icon"></i>{{ tab.label }}
        </Link>
        <span
            v-if="account"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium"
            :class="tabClass(active === 'manage')"
        >
            <i class="bi bi-gear"></i>{{ t('ftp.tabs.manage', { user: account.username }) }}
        </span>
    </nav>
</template>
