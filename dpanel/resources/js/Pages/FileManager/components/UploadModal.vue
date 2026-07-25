<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'upload'" class="space-y-3">
        <div
            class="rounded-lg border-2 border-dashed p-6 text-center transition"
            :class="fm.uploadDragActive ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-slate-300 dark:border-slate-700'"
            @dragover.prevent="fm.handleUploadDragOver"
            @dragenter.prevent="fm.handleUploadDragOver"
            @dragleave.prevent="fm.handleUploadDragLeave"
            @drop.prevent="fm.handleUploadDrop"
        >
            <i class="bi bi-cloud-arrow-up text-3xl text-slate-400"></i>
            <p class="mt-2 text-sm font-medium">Drag and drop files here</p>
            <p class="mt-1 text-xs text-slate-500">or choose from your computer</p>
            <input id="file-upload-input" type="file" multiple class="mt-3 w-full text-sm" @change="fm.handleUploadChange" />
            <div v-if="fm.uploadForm.uploads?.length" class="mt-2 space-y-1 text-left text-xs text-slate-600 dark:text-slate-300">
                <p>{{ fm.uploadForm.uploads.length }} file(s) selected</p>
                <p class="max-h-20 overflow-auto break-all">
                    {{ fm.uploadForm.uploads.map((file) => file.name).join(', ') }}
                </p>
            </div>
        </div>
        <button type="button" :disabled="fm.uploadForm.processing || !fm.uploadForm.uploads?.length" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitUpload">
            {{ fm.uploadForm.processing ? 'Uploading...' : 'Upload File(s)' }}
        </button>
        <div v-if="fm.uploadForm.processing || fm.uploadTaskComplete" class="space-y-1">
            <div class="flex items-center justify-between text-xs text-slate-500">
                <span>{{ fm.uploadTaskComplete ? 'Complete' : 'Uploading...' }}</span>
                <span>{{ fm.uploadProgress }}%</span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                <div class="h-full bg-blue-500 transition-all" :style="{ width: `${fm.uploadProgress}%` }"></div>
            </div>
        </div>
    </div>
</template>
