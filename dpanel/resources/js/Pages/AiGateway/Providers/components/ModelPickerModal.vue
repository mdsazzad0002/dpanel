<script setup>
import Modal from '@/Components/Modal.vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// The provider's models as a table: clicking a row fills the model form.
const props = defineProps({
    show: { type: Boolean, default: false },
    info: { type: Object, default: () => ({ models: [], source: null, loading: false }) },
});
const emit = defineEmits(['close', 'pick']);

const search = ref('');
watch(() => props.show, (open) => { if (open) search.value = ''; });

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.info.models;
    return props.info.models.filter((m) => (m.name || '').toLowerCase().includes(q) || (m.display_name || '').toLowerCase().includes(q));
});
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3 dark:border-slate-700">
            <h2 class="text-sm font-semibold">{{ t('aiGateway.models.pick') }}</h2>
            <button @click="emit('close')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">&times;</button>
        </div>
        <div class="border-b border-slate-200 px-5 py-2 dark:border-slate-700">
            <input v-model="search" type="text" :placeholder="t('aiGateway.models.search')" autofocus
                class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
        </div>
        <div class="max-h-96 overflow-y-auto px-2 py-2">
            <div v-if="info.loading" class="px-3 py-6 text-center text-sm text-slate-500">{{ t('aiGateway.models.loadingRemote') }}</div>
            <div v-else-if="!info.models.length" class="px-3 py-6 text-center text-sm text-slate-500">{{ t('aiGateway.models.noneFound') }}</div>
            <div v-else-if="!filtered.length" class="px-3 py-6 text-center text-sm text-slate-500">{{ t('aiGateway.models.noMatch', { query: search }) }}</div>
            <table v-else class="w-full text-left text-sm">
                <tbody>
                    <tr v-for="m in filtered" :key="m.name" @click="emit('pick', m)" class="cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-700/40">
                        <td class="px-3 py-2 font-medium">{{ m.display_name || m.name }}</td>
                        <td class="px-3 py-2 text-xs text-slate-400">{{ m.name }}</td>
                        <td class="px-3 py-2 text-xs text-slate-500">{{ (m.capabilities || []).map((c) => c.replace('_', ' ')).join(', ') }}</td>
                        <td class="px-3 py-2 text-right text-xs text-slate-400">{{ m.context_window ? m.context_window.toLocaleString() : '' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="info.source === 'seed'" class="border-t border-slate-200 px-5 py-2 text-xs text-amber-600 dark:border-slate-700">
            {{ t('aiGateway.models.localList') }}
        </div>
    </Modal>
</template>
