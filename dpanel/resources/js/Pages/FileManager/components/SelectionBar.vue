<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.selectedCount > 0" class="flex items-center gap-2 border-b border-sky-200/80 bg-sky-50/90 px-4 py-2 text-sm dark:border-sky-800/70 dark:bg-sky-900/20">
        <i class="bi bi-check2-circle text-blue-500"></i>
        <span class="font-medium text-blue-700 dark:text-blue-300">{{ fm.selectedCount }} selected</span>
        <button type="button" class="ml-1 text-xs text-blue-600 hover:underline dark:text-blue-400" @click="fm.clearSelection()">Clear</button>
        <div class="ml-auto flex gap-1">
            <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium hover:bg-blue-100 dark:hover:bg-blue-900/30" @click="fm.openModal('move')">
                <i class="bi bi-arrows-move mr-1"></i>Move
            </button>
            <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" @click="fm.deleteSelected">
                <i class="bi bi-trash mr-1"></i>Delete
            </button>
        </div>
    </div>
</template>
