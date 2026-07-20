<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'create-file'" class="space-y-3">
        <input v-model="fm.createFileForm.name" type="text" placeholder="file-name.ext" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <button type="button" :disabled="fm.createFileForm.processing || !fm.createFileForm.name" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitCreateFile">
            {{ fm.createFileForm.processing ? 'Creating...' : 'Create File' }}
        </button>
    </div>
</template>
