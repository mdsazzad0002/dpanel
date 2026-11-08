<script setup>
import { useI18n } from 'vue-i18n';

defineProps({
    rules: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['refresh', 'remove']);
const { t } = useI18n();

const portText = (port) => {
    if (!port) return t('firewall.anyPort');
    const range = port.to ? `${port.from}-${port.to}` : `${port.from}`;
    return `${range}/${port.protocol}`;
};
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex items-center justify-between border-b border-slate-200 p-4 dark:border-slate-800">
            <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('firewall.rules') }}</h2>
            <button type="button" :disabled="loading" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300" @click="emit('refresh')">
                <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': loading }"></i> {{ t('common.refresh') }}
            </button>
        </div>
        <div v-if="!rules.length" class="py-10 text-center text-sm text-slate-500">{{ loading ? t('common.loading') : t('firewall.empty') }}</div>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-slate-400">
                    <tr>
                        <th class="px-4 py-2">{{ t('firewall.action') }}</th>
                        <th class="px-4 py-2">{{ t('firewall.port') }}</th>
                        <th class="px-4 py-2">{{ t('firewall.source') }}</th>
                        <th class="px-4 py-2"><span class="sr-only">{{ t('firewall.remove') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="rule in rules" :key="rule.id">
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="rule.action === 'allow' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'">
                                {{ rule.action === 'allow' ? t('firewall.allow') : t('firewall.deny') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-mono">{{ portText(rule.port) }}</td>
                        <td class="px-4 py-3 font-mono">{{ rule.source || t('firewall.anywhere') }}</td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" :disabled="busy" class="rounded-lg px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 disabled:opacity-50 dark:hover:bg-red-950" @click="emit('remove', rule)">
                                <i class="bi bi-trash"></i> {{ t('firewall.remove') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="border-t border-slate-200 p-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">{{ t('firewall.onlyOurs') }}</p>
    </section>
</template>
