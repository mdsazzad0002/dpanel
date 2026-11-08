<script setup>
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';

defineProps({ busy: { type: Boolean, default: false } });
const emit = defineEmits(['submit']);
const { t } = useI18n();

const form = reactive({ action: 'allow', port_from: '', port_to: '', protocol: 'tcp', source: '' });

const submit = () => {
    const payload = { action: form.action, protocol: form.protocol };
    if (form.port_from !== '') payload.port_from = Number(form.port_from);
    if (form.port_to !== '') payload.port_to = Number(form.port_to);
    if (form.source.trim() !== '') payload.source = form.source.trim();
    emit('submit', payload);
};

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <form class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50" @submit.prevent="submit">
        <h2 class="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('firewall.addRule') }}</h2>
        <div class="grid gap-3 sm:grid-cols-6">
            <label class="text-xs text-slate-500">{{ t('firewall.action') }}
                <select v-model="form.action" :class="field">
                    <option value="allow">{{ t('firewall.allow') }}</option>
                    <option value="deny">{{ t('firewall.deny') }}</option>
                </select>
            </label>
            <label class="text-xs text-slate-500">{{ t('firewall.port') }}
                <input v-model="form.port_from" type="number" min="1" max="65535" placeholder="443" :class="field" />
            </label>
            <label class="text-xs text-slate-500">{{ t('firewall.portTo') }}
                <input v-model="form.port_to" type="number" min="1" max="65535" :placeholder="t('firewall.optional')" :class="field" />
            </label>
            <label class="text-xs text-slate-500">{{ t('firewall.protocol') }}
                <select v-model="form.protocol" :class="field">
                    <option value="tcp">TCP</option>
                    <option value="udp">UDP</option>
                </select>
            </label>
            <label class="text-xs text-slate-500 sm:col-span-2">{{ t('firewall.source') }}
                <input v-model="form.source" type="text" placeholder="203.0.113.0/24" :class="field" />
            </label>
        </div>
        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('firewall.formHelp') }}</p>
            <button type="submit" :disabled="busy" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ t('firewall.add') }}</button>
        </div>
    </form>
</template>
