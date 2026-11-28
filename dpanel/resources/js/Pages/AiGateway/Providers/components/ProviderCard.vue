<script setup>
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, watch } from 'vue';
import { useDragSort } from './useDragSort.js';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// One provider and the models it serves. Order is set by dragging: the
// provider by its handle among the providers, the models among themselves.
// Top runs first; everything else is edited in the offcanvas panels.
const props = defineProps({
    provider: { type: Object, required: true },
    position: { type: Number, required: true },
    dragHandle: { type: Object, default: () => ({}) }, // handle bindings from the page's provider list
    testing: { type: Object, required: true }, // model id -> true while a test runs
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['edit', 'add-model', 'edit-model', 'test-model', 'reordered']);

const models = ref([...props.provider.models]);
watch(() => props.provider.models, (list) => { models.value = [...list]; });

const drag = useDragSort(models, async (list) => {
    try {
        await axios.post(props.panelRoute('ai-gateway.providers.models.reorder', { provider: props.provider.id }), { ids: list.map((m) => m.id) });
        emit('reordered');
    } catch (e) {
        models.value = [...props.provider.models];
        alert(e.response?.data?.message || t('aiGateway.models.updateFailed'));
    }
});
const DROP = { 'drop-above': 'shadow-[inset_0_2px_0_0_#3b82f6]', 'drop-below': 'shadow-[inset_0_-2px_0_0_#3b82f6]' };

const toggleProvider = () => router.patch(props.panelRoute('ai-gateway.providers.toggle', { provider: props.provider.id }), { is_active: !props.provider.is_active }, { preserveScroll: true });
const removeProvider = () => {
    if (confirm(t('aiGateway.providers.deleteConfirm', { name: props.provider.name }))) router.delete(props.panelRoute('ai-gateway.providers.destroy', { provider: props.provider.id }), { preserveScroll: true });
};
const toggleModel = async (m) => {
    try {
        const { data } = await axios.patch(props.panelRoute('ai-gateway.models.update', { model: m.id }), { is_active: !m.is_active }, { headers: { Accept: 'application/json' } });
        Object.assign(m, data.model);
    } catch (e) {
        alert(e.response?.data?.message || t('aiGateway.models.updateFailed'));
    }
};
const removeModel = (m) => {
    if (confirm(t('aiGateway.models.removeConfirm', { name: m.name }))) router.delete(props.panelRoute('ai-gateway.models.destroy', { model: m.id }), { preserveScroll: true });
};

const fmtSeconds = (ms) => (ms >= 1000 ? `${(ms / 1000).toFixed(1)}s` : `${ms}ms`);
// What each probe (chat, code, tools, vision) found, so a pass or a failure
// says why instead of only colouring the row.
const probeNotes = (test) => Object.entries(test?.probes || {}).map(([name, probe]) => ({ name, ...probe }));
const btn = 'rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700';
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800" :class="provider.is_active ? '' : 'opacity-70'">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
            <span v-bind="dragHandle" class="cursor-grab touch-none text-slate-400 hover:text-slate-600 active:cursor-grabbing dark:hover:text-slate-200" :title="t('aiGateway.pm.dragHint')"><i class="bi bi-grip-vertical text-lg"></i></span>
            <span class="w-6 shrink-0 text-center text-xs font-semibold tabular-nums text-slate-400">#{{ position }}</span>
            <span class="inline-block h-2 w-2 shrink-0 rounded-full" :class="!provider.is_active ? 'bg-slate-400' : (provider.last_test_status === 'ok' ? 'bg-emerald-500' : (provider.last_test_status ? 'bg-red-500' : 'bg-amber-400'))" :title="provider.last_test_message || ''"></span>
            <button type="button" @click="emit('edit', provider)" class="min-w-0 text-left">
                <div class="truncate text-base font-semibold hover:underline">{{ provider.name }}</div>
                <div class="truncate text-xs text-slate-500">{{ provider.driver_label }}<template v-if="!provider.has_credentials"> · {{ t('aiGateway.pm.noKey') }}</template></div>
            </button>
            <button @click="toggleProvider" class="ml-auto rounded px-2 py-0.5 text-xs font-medium" :class="provider.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">
                {{ provider.is_active ? t('aiGateway.providers.active') : t('aiGateway.providers.inactive') }}
            </button>
            <div class="flex gap-1">
                <Link v-if="provider.models.length" :href="panelRoute('ai-gateway.chat', { provider: provider.id })" :class="btn">{{ t('aiGateway.providers.chat') }}</Link>
                <button @click="emit('edit', provider)" :class="btn">{{ t('aiGateway.providers.edit') }}</button>
                <button @click="removeProvider" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">{{ t('aiGateway.providers.delete') }}</button>
            </div>
        </div>

        <div v-if="!models.length" class="flex items-center justify-between px-4 py-3 text-sm text-slate-500">
            {{ t('aiGateway.pm.noModels') }}
            <button @click="emit('add-model', provider.id)" :class="btn">+ {{ t('aiGateway.models.add') }}</button>
        </div>
        <div v-else class="overflow-x-auto">
            <!-- Model rows are kept at least 20% smaller than the provider row (12px vs 16px). -->
            <table class="min-w-full divide-y divide-slate-200 text-xs dark:divide-slate-700">
                <thead class="bg-slate-50 text-left text-[11px] text-slate-500 dark:bg-slate-800/60">
                    <tr>
                        <th class="w-16 px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">{{ t('aiGateway.models.col.model') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('aiGateway.models.col.context') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('aiGateway.models.col.capabilities') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('aiGateway.models.col.lastTest') }}</th>
                        <th class="px-4 py-2 text-right font-medium">
                            <button @click="emit('add-model', provider.id)" class="font-normal text-blue-600 hover:underline">+ {{ t('aiGateway.models.add') }}</button>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <tr v-for="(m, i) in models" :key="m.id" v-bind="drag.row(i)" class="hover:bg-slate-50 dark:hover:bg-slate-700/40"
                        :class="[m.is_active ? '' : 'opacity-60', DROP[drag.dropClass(i)], drag.isDragging(i) ? 'opacity-40' : '']">
                        <td class="whitespace-nowrap px-4 py-1.5">
                            <span v-bind="drag.handle(i)" class="cursor-grab touch-none text-slate-400 hover:text-slate-600 active:cursor-grabbing dark:hover:text-slate-200" :title="t('aiGateway.pm.dragHint')"><i class="bi bi-grip-vertical"></i></span>
                            <span class="ml-1 tabular-nums text-slate-400">{{ i + 1 }}</span>
                        </td>
                        <td class="px-4 py-1.5">
                            <button type="button" @click="emit('edit-model', m)" class="text-left font-medium hover:underline">{{ m.display_name || m.name }}</button>
                            <div class="text-[10px] text-slate-400">{{ m.name }}</div>
                        </td>
                        <td class="px-4 py-1.5 text-slate-500">{{ m.context_window ? m.context_window.toLocaleString() : '—' }}</td>
                        <td class="px-4 py-1.5 text-slate-500">{{ (m.capabilities || []).join(', ') || '—' }}</td>
                        <td class="max-w-xs px-4 py-1.5">
                            <span v-if="testing[m.id]" class="text-amber-600"><i class="bi bi-hourglass-split"></i> {{ t('aiGateway.models.testing') }}</span>
                            <div v-else-if="m.last_test">
                                <div :class="m.last_test.ok ? 'text-emerald-600' : 'text-red-600'">
                                    <i class="bi" :class="m.last_test.ok ? 'bi-check-circle' : 'bi-x-circle'"></i>
                                    {{ m.last_test.ok ? t('aiGateway.models.statusWorking') : t('aiGateway.models.statusFailed') }}
                                    <span class="text-slate-400"><template v-if="m.last_test.latency_ms"> · {{ fmtSeconds(m.last_test.latency_ms) }}</template><template v-if="m.last_test.ok && m.last_test.tokens_per_second"> · {{ m.last_test.tokens_per_second }} tok/s</template></span>
                                </div>
                                <!-- The reason in words: the model's own reply when it answered,
                                     the provider's or panel's error when it didn't. -->
                                <div v-if="m.last_test.message" class="mt-0.5 break-words text-[11px] leading-snug"
                                    :class="m.last_test.ok ? 'text-slate-500' : 'text-red-600'" :title="m.last_test.message">
                                    {{ m.last_test.message }}
                                </div>
                                <div v-if="probeNotes(m.last_test).length" class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="probe in probeNotes(m.last_test)" :key="probe.name" :title="probe.note"
                                        class="rounded px-1 py-0.5 text-[10px]"
                                        :class="probe.ok ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300'">
                                        <i class="bi" :class="probe.ok ? 'bi-check' : 'bi-x'"></i> {{ t('aiGateway.models.probe.' + probe.name, probe.name) }}: {{ probe.note }}
                                    </span>
                                </div>
                            </div>
                            <span v-else-if="!m.testable" class="text-slate-400">{{ t('aiGateway.models.notChat') }}</span>
                            <span v-else class="text-slate-400">{{ t('aiGateway.models.notTested') }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-1.5 text-right">
                            <div class="inline-flex gap-1">
                                <button v-if="m.testable" @click="emit('test-model', m)" :disabled="!!testing[m.id]" :title="t('aiGateway.models.testHint')" :class="btn"><i class="bi bi-play"></i> {{ t('aiGateway.models.test') }}</button>
                                <button @click="emit('edit-model', m)" :class="btn">{{ t('aiGateway.models.edit') }}</button>
                                <button @click="toggleModel(m)" :title="m.auto_disabled ? t('aiGateway.models.autoDisabledHint', { count: m.failure_count }) : ''"
                                    class="rounded border px-2 py-1 hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700"
                                    :class="m.auto_disabled ? 'border-amber-300 text-amber-700' : 'border-slate-300'">{{ m.auto_disabled ? t('aiGateway.models.autoDisabled') : (m.is_active ? t('aiGateway.models.active') : t('aiGateway.models.inactive')) }}</button>
                                <button @click="removeModel(m)" class="rounded border border-red-300 px-2 py-1 text-red-600 hover:bg-red-50 dark:border-red-700">{{ t('aiGateway.models.delete') }}</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
