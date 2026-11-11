<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/composables/usePanelApi';

defineProps({
    tokens: { type: Array, default: () => [] },
});
const emit = defineEmits(['revoked']);
const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);

const busyId = ref(null);
const error = ref('');

const when = (value) => (value ? new Date(value).toLocaleString() : '');

const revoke = async (token) => {
    if (!window.confirm(t('apiTokens.revokeConfirm', { name: token.name }))) return;
    busyId.value = token.id;
    error.value = '';
    try {
        await window.axios.delete(panelRoute('settings.api-tokens.destroy', { apiToken: token.id }));
        emit('revoked', token.id);
    } catch (e) {
        error.value = errorMessage(e, t('apiTokens.revokeFailed'));
    } finally {
        busyId.value = null;
    }
};
</script>

<template>
    <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <h2 class="text-sm font-semibold">{{ t('apiTokens.yourTokens') }}</h2>
        <div v-if="error" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
        <p v-if="!tokens.length" class="mt-3 text-sm text-slate-500">{{ t('apiTokens.empty') }}</p>
        <ul v-else class="mt-3 divide-y divide-slate-200 dark:divide-slate-800">
            <li v-for="token in tokens" :key="token.id" class="flex flex-wrap items-start gap-3 py-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">
                        {{ token.name }}
                        <code class="ml-1 text-xs text-slate-400">{{ token.token_prefix }}</code>
                        <span v-if="token.expired" class="ml-1 rounded-md bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ t('apiTokens.expired') }}</span>
                    </p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <code v-for="scope in token.scopes" :key="scope" class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] dark:bg-slate-800">{{ scope }}</code>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ token.last_used_at ? t('apiTokens.lastUsed', { when: when(token.last_used_at), ip: token.last_used_ip || '-' }) : t('apiTokens.neverUsed') }}
                        ·
                        {{ token.expires_at ? t('apiTokens.expiresOn', { when: when(token.expires_at) }) : t('apiTokens.noExpiry') }}
                    </p>
                </div>
                <button type="button" :disabled="busyId === token.id" class="rounded-lg border border-red-200 px-3 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-900 dark:hover:bg-red-950" @click="revoke(token)">{{ t('apiTokens.revoke') }}</button>
            </li>
        </ul>
    </div>
</template>
