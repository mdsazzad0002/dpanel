<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useWordpressApi } from '../useWordpressApi';

const props = defineProps({ site: { type: Object, required: true } });
const { t } = useI18n();
const api = useWordpressApi();

const checks = ref(null);
const running = ref(false);
const fixing = ref('');
const error = ref('');

const icon = { pass: 'bi-check-circle-fill text-emerald-600', warn: 'bi-exclamation-triangle-fill text-amber-500', fail: 'bi-x-octagon-fill text-red-600' };
const score = computed(() => (checks.value || []).filter((check) => check.status === 'pass').length);

const run = async () => {
    running.value = true;
    error.value = '';
    try {
        checks.value = (await api.get('wordpress.security', { site: props.site.id })).checks || [];
    } catch (e) {
        error.value = api.message(e, t('wordpress.checkFailed'));
    } finally {
        running.value = false;
    }
};

const fix = async (name) => {
    if (name === 'reinstall_core' && !window.confirm(t('wordpress.reinstallConfirm'))) return;
    fixing.value = name;
    error.value = '';
    try {
        checks.value = (await api.post('wordpress.fix', { site: props.site.id }, { fix: name })).checks || [];
    } catch (e) {
        error.value = api.message(e, t('wordpress.fixFailed'));
    } finally {
        fixing.value = '';
    }
};
</script>

<template>
    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('wordpress.securityHelp') }}</p>
            <button type="button" :disabled="running" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="run">
                <i v-if="running" class="bi bi-arrow-repeat mr-1 animate-spin"></i>{{ checks ? t('wordpress.checkAgain') : t('wordpress.runCheck') }}
            </button>
        </div>
        <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
        <template v-if="checks">
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('wordpress.score', { passed: score, total: checks.length }) }}</p>
            <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                <li v-for="check in checks" :key="check.key" class="flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
                    <i class="bi text-lg" :class="icon[check.status]" aria-hidden="true"></i>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-100">{{ t(`wordpress.checks.${check.key}.${check.status === 'pass' ? 'pass' : 'issue'}`) }}</p>
                        <p v-if="check.detail" class="truncate font-mono text-xs text-slate-500">{{ check.detail }}</p>
                    </div>
                    <button v-if="check.fix" type="button" :disabled="!!fixing" class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-semibold disabled:opacity-50 dark:border-slate-700" @click="fix(check.fix)">
                        <i v-if="fixing === check.fix" class="bi bi-arrow-repeat mr-1 animate-spin"></i>{{ t(`wordpress.fixes.${check.fix}`) }}
                    </button>
                </li>
            </ul>
        </template>
    </div>
</template>
