<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    policy: { type: Object, default: null },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['save']);

const maxRetry = ref(3);
watch(() => props.policy, (policy) => {
    maxRetry.value = policy?.max_retry || policy?.default_max_retry || 3;
}, { immediate: true });
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-semibold">{{ t('security.fail2ban.policy.title') }}</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ t('security.fail2ban.policy.help') }}
        </p>

        <div v-if="policy" class="mt-3 flex flex-wrap gap-2 text-xs">
            <span class="rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">
                <i18n-t keypath="security.fail2ban.policy.now" scope="global"><template #count><strong>{{ policy.max_retry ?? '?' }}</strong></template></i18n-t>
            </span>
            <span :class="policy.permanent ? 'border-emerald-300 text-emerald-700 dark:border-emerald-800 dark:text-emerald-300' : 'border-amber-300 text-amber-700 dark:border-amber-800 dark:text-amber-300'" class="rounded-full border px-3 py-1">
                {{ policy.permanent ? t('security.fail2ban.policy.permanent') : t('security.fail2ban.policy.temporary') }}
            </span>
            <span class="rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">
                {{ t('security.fail2ban.policy.port', { ports: (policy.ports || []).join(', ') }) }}
            </span>
        </div>
        <p v-if="policy?.temporary_jails?.length" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            {{ t('security.fail2ban.policy.tempJails', { jails: policy.temporary_jails.join(', ') }) }}
        </p>

        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="emit('save', maxRetry)">
            <label class="text-sm">
                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('security.fail2ban.policy.maxRetry') }}</span>
                <input v-model.number="maxRetry" type="number" min="1" max="10" required class="mt-1 w-28 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <button type="submit" :disabled="busy" class="rounded-md bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                {{ busy ? t('security.fail2ban.policy.saving') : t('common.save') }}
            </button>
        </form>
    </section>
</template>
