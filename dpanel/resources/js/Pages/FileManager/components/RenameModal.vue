<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'rename'" class="space-y-3">
        <input v-model="fm.renameForm.new_name" type="text" placeholder="new name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <button type="button" :disabled="fm.renameForm.processing || !fm.renameForm.new_name" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitRename">
            {{ fm.renameForm.processing ? 'Renaming...' : 'Rename' }}
        </button>
    </div>
</template>
