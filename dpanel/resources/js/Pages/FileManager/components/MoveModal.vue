<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'move'" class="space-y-3">
        <p class="text-xs text-slate-500">Selected items: {{ fm.selectedCount || (fm.singleSelectedItem ? 1 : 0) }}</p>
        <input v-model="fm.moveForm.destination_path" type="text" placeholder="destination folder path (empty = root)" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <p class="text-xs text-slate-500">You can also drag selected rows and drop onto sidebar folders.</p>
        <button type="button" :disabled="fm.moveForm.processing || (!fm.selectedCount && !fm.singleSelectedItem)" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitMove">
            {{ fm.moveForm.processing ? 'Moving...' : 'Move' }}
        </button>
    </div>
</template>
