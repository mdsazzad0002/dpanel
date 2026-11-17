<script setup>
import { flag } from '../loginEventFormat';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    stats: { type: Object, required: true },
});

const emit = defineEmits(['country']);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.stats.events') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ stats.total.toLocaleString() }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.stats.success') }}</p>
            <p class="mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ stats.success.toLocaleString() }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.stats.failed') }}</p>
            <p class="mt-1 text-2xl font-semibold text-red-600 dark:text-red-400">{{ stats.failed.toLocaleString() }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.stats.last24h', { count: stats.failed_24h.toLocaleString() }) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.stats.uniqueIps') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ stats.unique_ips.toLocaleString() }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.stats.topCountries') }}</p>
            <div v-if="stats.countries.length" class="mt-2 flex flex-wrap gap-1.5">
                <button
                    v-for="country in stats.countries"
                    :key="country.country_code"
                    type="button"
                    :title="t('security.loginAudit.stats.showOnly', { country: country.country })"
                    class="rounded-md border border-slate-200 px-2 py-0.5 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="emit('country', country.country_code)"
                >
                    {{ flag(country.country_code) }} {{ country.country_code }} · {{ country.total }}
                </button>
            </div>
            <p v-else class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('security.loginAudit.stats.noneYet') }}</p>
        </div>
    </div>
</template>
