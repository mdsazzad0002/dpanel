<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// One IP has one PTR, shared by every mail domain, so PTR, the Postfix
// hostname and the system hostname should all be the same name.
const props = defineProps({
    state: { type: Object, required: true },
});

const host = computed(() => props.state.current || props.state.best || '');

const checks = computed(() => [
    {
        label: t('email.serverIdentity.ptrLabel', { ip: props.state.ip || t('email.serverIdentity.serverIp') }),
        value: props.state.ptr || t('email.serverIdentity.notSet'),
        ok: !!host.value && props.state.ptr === host.value,
        hint: props.state.ip
            ? t('email.serverIdentity.ptrHint', { ip: props.state.ip, host: host.value || t('email.serverIdentity.yourHostname') })
            : t('email.serverIdentity.ipUnknown', { env: 'dPanel_MAIL_SERVER_IP' }),
    },
    {
        label: t('email.serverIdentity.systemHostname'),
        value: props.state.system || '—',
        ok: !!host.value && props.state.system === host.value,
        hint: t('email.serverIdentity.systemHint'),
    },
]);
</script>

<template>
    <ul class="mt-4 space-y-2 text-sm">
        <li v-for="check in checks" :key="check.label" class="rounded-md border px-3 py-2" :class="check.ok ? 'border-emerald-200 dark:border-emerald-900' : 'border-amber-200 bg-amber-50/50 dark:border-amber-900 dark:bg-amber-950/30'">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-slate-600 dark:text-slate-300">{{ check.label }}</span>
                <code class="text-xs font-semibold" :class="check.ok ? 'text-emerald-600' : 'text-amber-700 dark:text-amber-300'">{{ check.value }} {{ check.ok ? '✓' : '' }}</code>
            </div>
            <p v-if="!check.ok" class="mt-1 text-xs text-slate-500">{{ check.hint }}</p>
        </li>
    </ul>
</template>
