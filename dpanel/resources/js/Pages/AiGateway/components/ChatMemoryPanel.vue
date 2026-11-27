<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// What the chat remembers about this admin across conversations. The AI
// saves entries itself; here they can be read and deleted.
const props = defineProps({
    open: { type: Boolean, default: false },
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['close']);

const memories = ref([]);
const loading = ref(false);
const error = ref('');
const expanded = ref(null);

const typeClass = {
    user: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
    feedback: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    project: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    reference: 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300',
};
const count = computed(() => memories.value.length);

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(props.panelRoute('ai-gateway.memories.index'));
        memories.value = data.memories || [];
    }
    catch (e) {
        error.value = e?.response?.data?.message || e?.message || t('aiGateway.memory.loadFailed');
    }
    finally {
        loading.value = false;
    }
};

const remove = async (memory) => {
    if (!confirm(t('aiGateway.memory.deleteConfirm', { name: memory.name }))) return;
    await window.axios.delete(props.panelRoute('ai-gateway.memories.destroy', { memory: memory.id }));
    memories.value = memories.value.filter((m) => m.id !== memory.id);
};

const clearAll = async () => {
    if (!confirm(t('aiGateway.memory.clearConfirm'))) return;
    await window.axios.delete(props.panelRoute('ai-gateway.memories.clear'));
    memories.value = [];
};

watch(() => props.open, (open) => { if (open) load(); }, { immediate: true });
</script>

<template>
    <transition enter-from-class="translate-x-full" enter-active-class="transition duration-200" leave-to-class="translate-x-full" leave-active-class="transition duration-150">
        <aside v-if="open" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-900" :aria-label="t('aiGateway.memory.title')" @keydown.esc="emit('close')">
            <header class="flex items-center gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <i class="bi bi-journal-bookmark text-violet-500"></i>
                <h2 class="font-semibold">{{ t('aiGateway.memory.title') }}</h2>
                <span class="rounded-full bg-slate-100 px-2 text-xs text-slate-500 dark:bg-slate-800">{{ count }}</span>
                <button type="button" class="ml-auto flex h-8 w-8 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" :title="t('aiGateway.memory.close')" @click="emit('close')">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>
            <p class="border-b border-slate-100 px-4 py-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">{{ t('aiGateway.memory.help') }}</p>

            <div class="flex-1 overflow-y-auto p-3">
                <p v-if="loading" class="p-4 text-sm text-slate-500"><i class="bi bi-arrow-repeat animate-spin"></i> {{ t('aiGateway.memory.loading') }}</p>
                <p v-else-if="error" class="p-4 text-sm text-red-600">{{ error }}</p>
                <p v-else-if="!count" class="p-6 text-center text-sm text-slate-500">{{ t('aiGateway.memory.empty') }}</p>
                <ul v-else class="space-y-2">
                    <li v-for="memory in memories" :key="memory.id" class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                        <div class="flex items-start gap-2">
                            <button type="button" class="min-w-0 flex-1 text-left" :aria-expanded="expanded === memory.id" @click="expanded = expanded === memory.id ? null : memory.id">
                                <div class="flex items-center gap-2">
                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase" :class="typeClass[memory.type]">{{ memory.type }}</span>
                                    <span class="truncate font-mono text-xs text-slate-500">{{ memory.name }}</span>
                                </div>
                                <p class="mt-1 text-sm">{{ memory.description }}</p>
                            </button>
                            <button type="button" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10" :title="t('aiGateway.memory.delete')" @click="remove(memory)">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </div>
                        <p v-if="expanded === memory.id" class="mt-2 whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-2 text-xs text-slate-700 dark:bg-slate-950 dark:text-slate-300">{{ memory.body }}</p>
                    </li>
                </ul>
            </div>

            <footer v-if="count" class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                <button type="button" class="text-xs font-medium text-rose-600 hover:underline" @click="clearAll">{{ t('aiGateway.memory.clear') }}</button>
            </footer>
        </aside>
    </transition>
</template>
