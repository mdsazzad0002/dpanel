<script setup>
import { useI18n } from 'vue-i18n';

defineProps({ forwarders: { type: Array, required: true }, busy: { type: Boolean, default: false } });
defineEmits(['remove']);
const { t } = useI18n();
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-100">{{ t('mailForwarders.list') }}</h2>
        <p v-if="!forwarders.length" class="px-4 py-6 text-center text-sm text-slate-500">{{ t('mailForwarders.empty') }}</p>
        <div v-else class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-slate-800/60">
                    <tr><th class="px-4 py-2">{{ t('mailForwarders.address') }}</th><th class="px-4 py-2">{{ t('mailForwarders.goesTo') }}</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="forwarder in forwarders" :key="forwarder.id">
                        <td class="px-4 py-2 font-medium text-slate-800 dark:text-slate-100">{{ forwarder.address }}</td>
                        <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ forwarder.destinations.join(', ') }}</td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" :disabled="busy" class="rounded-lg px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 disabled:opacity-50 dark:hover:bg-red-950" @click="$emit('remove', forwarder)">{{ t('mailForwarders.remove') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
