<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    backups: { type: Array, default: () => [] },
    // v-model: array of selected backup ids
    modelValue: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue', 'done']);

const deleting = ref(false);

const allIds = computed(() => props.backups.map((backup) => backup.id));
const allSelected = computed(() => allIds.value.length > 0 && props.modelValue.length === allIds.value.length);
const someSelected = computed(() => props.modelValue.length > 0 && !allSelected.value);

const toggleAll = () => emit('update:modelValue', allSelected.value ? [] : [...allIds.value]);

const deleteSelected = async () => {
    const ids = [...props.modelValue];
    if (ids.length === 0) return;
    if (!confirm(`Permanently delete ${ids.length} trash backup(s)? Deleted archives cannot be restored.`)) return;

    deleting.value = true;
    try {
        const response = await fetch(route('trash-backups.bulk-destroy'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ ids }),
        });
        const payload = await response.json().catch(() => ({}));
        emit('done', {
            type: response.ok ? (payload.type || 'success') : 'error',
            message: payload.message || `Delete failed with HTTP ${response.status}.`,
            deleted: Array.isArray(payload.deleted) ? payload.deleted : [],
        });
    } catch (error) {
        emit('done', { type: 'error', message: error?.message || 'Trash backup delete failed.', deleted: [] });
    } finally {
        deleting.value = false;
    }
};
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3 dark:border-slate-800">
        <label class="flex items-center gap-2 text-sm font-medium">
            <input
                type="checkbox"
                :checked="allSelected"
                :indeterminate="someSelected"
                class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                @change="toggleAll"
            />
            {{ modelValue.length > 0 ? `${modelValue.length} selected` : 'Select all' }}
        </label>
        <button
            type="button"
            :disabled="modelValue.length === 0 || deleting"
            class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50"
            @click="deleteSelected"
        >
            <i :class="deleting ? 'bi bi-arrow-repeat animate-spin' : 'bi bi-trash3'"></i>
            {{ deleting ? 'Deleting...' : 'Delete Selected' }}
        </button>
    </div>
</template>
