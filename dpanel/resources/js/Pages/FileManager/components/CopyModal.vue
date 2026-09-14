<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'copy'" class="space-y-3">
        <p class="text-xs text-slate-500">Selected items: {{ fm.selectedCount || (fm.singleSelectedItem ? 1 : 0) }}</p>
        <input v-model="fm.copyForm.destination_path" type="text" placeholder="destination folder path (empty = root)" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <div v-if="(fm.copyForm.item_paths || []).length === 1">
            <label class="mb-1 block text-xs font-medium text-slate-500">Name the copy</label>
            <input v-model="fm.copyForm.new_name" type="text" placeholder="new name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        </div>
        <p class="text-xs text-slate-500">The original item is kept in place; a copy is created at the destination.</p>
        <button type="button" :disabled="fm.copyForm.processing || (!fm.selectedCount && !fm.singleSelectedItem)" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitCopy">
            {{ fm.copyForm.processing ? 'Copying...' : 'Copy' }}
        </button>
    </div>
</template>
