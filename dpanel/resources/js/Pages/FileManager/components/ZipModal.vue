<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'zip'" class="space-y-3">
        <p class="text-xs text-slate-500">Selected items: {{ fm.selectedCount || (fm.singleSelectedItem ? 1 : 0) }}</p>
        <input v-model="fm.zipForm.zip_name" type="text" placeholder="archive-name.zip" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <button type="button" :disabled="fm.zipForm.processing || (!fm.selectedCount && !fm.singleSelectedItem)" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitZip">
            {{ fm.zipForm.processing ? 'Creating Zip...' : 'Create Zip' }}
        </button>
    </div>
</template>
