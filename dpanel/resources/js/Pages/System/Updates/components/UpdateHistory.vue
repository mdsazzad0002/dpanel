<script setup>
import { useI18n } from 'vue-i18n';
import { runLabels, when } from './runLabels';

// Past updates and rollbacks; a finished update with a backup can be rolled back (backlog U6).
defineProps({
    runs: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['show', 'rollback']);
const { t } = useI18n();
const labels = runLabels(t);

const versions = (run) => [run.from_version || run.from_ref, run.to_version || run.target_ref].filter(Boolean).join(' → ');
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <h2 class="mb-2 text-sm font-semibold">{{ t('panelUpdate.history') }}</h2>
        <p v-if="!runs.length" class="text-xs text-slate-500">{{ t('panelUpdate.noHistory') }}</p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-slate-500">
                    <tr>
                        <th class="py-1 pr-3">{{ t('panelUpdate.started') }}</th>
                        <th class="py-1 pr-3">{{ t('panelUpdate.what') }}</th>
                        <th class="py-1 pr-3">{{ t('panelUpdate.versions') }}</th>
                        <th class="py-1 pr-3">{{ t('panelUpdate.result') }}</th>
                        <th class="py-1 pr-3">{{ t('panelUpdate.by') }}</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="run in runs" :key="run.run_id">
                        <td class="whitespace-nowrap py-1.5 pr-3 text-xs">{{ when(run.started_at) }}</td>
                        <td class="py-1.5 pr-3">{{ labels.action(run) }}</td>
                        <td class="py-1.5 pr-3 text-xs">{{ versions(run) }}</td>
                        <td class="py-1.5 pr-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="labels.tone(run)" :title="run.message || ''">{{ labels.status(run) }}</span>
                        </td>
                        <td class="py-1.5 pr-3 text-xs">{{ run.user || '—' }}</td>
                        <td class="whitespace-nowrap py-1.5 text-right text-xs">
                            <button type="button" class="font-semibold text-blue-600" @click="emit('show', run)">{{ t('panelUpdate.log') }}</button>
                            <button
                                v-if="run.action === 'update' && run.status === 'succeeded' && run.has_backup"
                                type="button"
                                :disabled="busy"
                                class="ml-3 font-semibold text-amber-600 disabled:opacity-50"
                                @click="emit('rollback', run)"
                            >{{ t('panelUpdate.rollBack') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
