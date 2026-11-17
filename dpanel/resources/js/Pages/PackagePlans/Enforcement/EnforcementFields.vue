<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
// What happens when an account goes over its package limits (tasks P2, P3).
defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm">{{ t('packagePlans.enforcement.disk') }}</label>
        <select v-model="form.enforcement_mode" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="report">{{ t('packagePlans.enforcement.report') }}</option>
            <option value="enforce">{{ t('packagePlans.enforcement.enforce') }}</option>
        </select>
        <p class="mt-1 text-xs text-slate-500">{{ t('packagePlans.enforcement.diskHelp') }}</p>
        <p v-if="form.errors.enforcement_mode" class="mt-1 text-xs text-red-600">{{ form.errors.enforcement_mode }}</p>
    </div>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm">{{ t('packagePlans.enforcement.bandwidth') }}</label>
        <select v-model="form.over_bandwidth_action" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="notify">{{ t('packagePlans.enforcement.notify') }}</option>
            <option value="block">{{ t('packagePlans.enforcement.block') }}</option>
        </select>
        <p class="mt-1 text-xs text-slate-500">{{ t('packagePlans.enforcement.bandwidthHelp') }}</p>
        <p v-if="form.errors.over_bandwidth_action" class="mt-1 text-xs text-red-600">{{ form.errors.over_bandwidth_action }}</p>
    </div>
    <div>
        <label class="mb-1 block text-sm">{{ t('packagePlans.enforcement.emailsHour') }}</label>
        <input v-model.number="form.max_emails_per_hour" type="number" min="0" max="1000000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <p v-if="form.errors.max_emails_per_hour" class="mt-1 text-xs text-red-600">{{ form.errors.max_emails_per_hour }}</p>
    </div>
    <div>
        <label class="mb-1 block text-sm">{{ t('packagePlans.enforcement.emailsDay') }}</label>
        <input v-model.number="form.max_emails_per_day" type="number" min="0" max="10000000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <p v-if="form.errors.max_emails_per_day" class="mt-1 text-xs text-red-600">{{ form.errors.max_emails_per_day }}</p>
    </div>
    <div class="grid gap-4 md:col-span-2 md:grid-cols-3">
        <div>
            <label class="mb-1 block text-sm">{{ t('packagePlans.enforcement.cpu') }}</label>
            <input v-model.number="form.max_cpu_percent" type="number" min="0" max="6400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <p v-if="form.errors.max_cpu_percent" class="mt-1 text-xs text-red-600">{{ form.errors.max_cpu_percent }}</p>
        </div>
        <div>
            <label class="mb-1 block text-sm">{{ t('packagePlans.enforcement.memory') }}</label>
            <input v-model.number="form.max_memory_mb" type="number" min="0" max="1048576" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <p v-if="form.errors.max_memory_mb" class="mt-1 text-xs text-red-600">{{ form.errors.max_memory_mb }}</p>
        </div>
        <div>
            <label class="mb-1 block text-sm">{{ t('packagePlans.enforcement.processes') }}</label>
            <input v-model.number="form.max_processes" type="number" min="0" max="100000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <p v-if="form.errors.max_processes" class="mt-1 text-xs text-red-600">{{ form.errors.max_processes }}</p>
        </div>
    </div>
    <p class="text-xs text-slate-500 md:col-span-2">
        {{ t('packagePlans.enforcement.phpHelp') }}
    </p>
    <p class="text-xs text-slate-500 md:col-span-2">
        {{ t('packagePlans.enforcement.mailHelp') }}
    </p>
</template>
