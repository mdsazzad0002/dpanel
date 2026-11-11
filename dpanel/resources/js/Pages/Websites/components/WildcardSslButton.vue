<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

/**
 * "Wildcard SSL" on or off (backlog M4): the certificate also covers
 * *.<domain>. The CA checks a TXT record, so the domain's DNS must be on
 * this server or on Cloudflare; the server says so when it is not.
 */
const props = defineProps({
    website: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['changed', 'failed']);

const { t } = useI18n();
const { panelRoute, requestJson } = usePanelApi();
const saving = ref(false);
const enabled = computed(() => Boolean(props.website?.ssl_wildcard));
const domain = computed(() => String(props.website?.domain || ''));
// Only a main domain: not an alias, not www, not an address.
const eligible = computed(() => !props.website?.parent_id
    && !domain.value.startsWith('www.')
    && !/^\d{1,3}(\.\d{1,3}){3}$/.test(domain.value));

const toggle = async () => {
    if (saving.value) return;
    const question = enabled.value ? t('wildcardSsl.confirmOff', { domain: domain.value }) : t('wildcardSsl.confirmOn', { domain: domain.value });
    if (!window.confirm(question)) return;
    saving.value = true;
    try {
        const data = await requestJson(panelRoute('websites.ssl.wildcard', { id: props.website.id }), {
            method: 'PATCH',
            body: { enabled: !enabled.value },
        });
        emit('changed', data);
    } catch (error) {
        emit('failed', error?.message || t('wildcardSsl.failed'));
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <button v-if="eligible" type="button" :disabled="disabled || saving" :title="t('wildcardSsl.help', { domain })" class="rounded-md border border-violet-200 px-2.5 py-1.5 text-xs font-semibold text-violet-600 hover:border-violet-300 hover:bg-violet-50 disabled:opacity-60 dark:border-violet-800 dark:text-violet-400 dark:hover:bg-violet-500/10" @click="toggle">
        <template v-if="saving">{{ t('wildcardSsl.working') }}</template>
        <template v-else-if="enabled">{{ t('wildcardSsl.turnOff') }}</template>
        <template v-else>{{ t('wildcardSsl.turnOn') }}</template>
    </button>
</template>
