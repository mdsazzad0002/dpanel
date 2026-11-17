<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    filters: { type: Object, required: true },
    sshSupported: { type: Boolean, default: true },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['apply']);

const field = 'rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <form class="flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="emit('apply')">
        <label class="min-w-0 flex-1 basis-56">
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ t('common.search') }}</span>
            <input v-model="filters.search" type="search" :placeholder="t('security.loginAudit.filters.searchPlaceholder')" :class="field" class="w-full" />
        </label>
        <label v-if="sshSupported">
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.filters.source') }}</span>
            <select v-model="filters.channel" :class="field" @change="emit('apply')">
                <option value="">{{ t('security.loginAudit.filters.panelSsh') }}</option>
                <option value="panel">{{ t('security.loginAudit.channels.panel') }}</option>
                <option value="ssh">SSH</option>
            </select>
        </label>
        <label>
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.filters.result') }}</span>
            <select v-model="filters.result" :class="field" @change="emit('apply')">
                <option value="">{{ t('security.loginAudit.filters.all') }}</option>
                <option value="success">{{ t('security.loginAudit.results.success') }}</option>
                <option value="failed">{{ t('security.loginAudit.results.failed') }}</option>
                <option value="logout">{{ t('security.loginAudit.results.logout') }}</option>
            </select>
        </label>
        <label>
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.filters.from') }}</span>
            <input v-model="filters.from" type="date" :class="field" @change="emit('apply')" />
        </label>
        <label>
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.filters.to') }}</span>
            <input v-model="filters.to" type="date" :class="field" @change="emit('apply')" />
        </label>
        <div v-if="filters.country" class="flex items-center gap-1 rounded-md bg-slate-100 px-2 py-2 text-xs dark:bg-slate-800">
            {{ t('security.loginAudit.filters.country', { country: filters.country }) }}
            <button type="button" class="text-slate-500 hover:text-red-600" :title="t('security.loginAudit.filters.remove')" @click="filters.country = ''; emit('apply')">
                <i class="bi bi-x-lg" />
            </button>
        </div>
        <button type="submit" :disabled="loading" class="rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-700 disabled:opacity-60 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">
            {{ t('common.search') }}
        </button>
    </form>
</template>
