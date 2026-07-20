<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'unzip'" class="space-y-3">
        <p class="text-xs text-slate-500 break-all">Zip file: {{ fm.unzipForm.zip_path }}</p>
        <button type="button" :disabled="fm.unzipForm.processing || !fm.unzipForm.zip_path" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitUnzip">
            {{ fm.unzipForm.processing ? 'Extracting...' : 'Extract Zip' }}
        </button>
    </div>
</template>
