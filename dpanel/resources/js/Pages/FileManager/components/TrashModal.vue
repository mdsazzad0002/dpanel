<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'trash'" class="min-h-0 flex-1 space-y-3 overflow-y-auto">
        <p class="text-xs text-slate-500">Deleted items are zipped and kept here before being permanently removed.</p>

        <div v-if="fm.trashLoading" class="py-6 text-center text-sm text-slate-500">Loading…</div>
        <div v-else-if="fm.trashItems.length === 0" class="py-6 text-center text-sm text-slate-500">Trash is empty.</div>

        <template v-else>
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-2 dark:border-slate-700">
                <label class="flex items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-300">
                    <input
                        type="checkbox"
                        :checked="fm.trashSelectedPaths.length > 0 && fm.trashSelectedPaths.length === fm.trashItems.length"
                        @change="fm.toggleSelectAllTrash()"
                    />
                    {{ fm.trashSelectedPaths.length ? `${fm.trashSelectedPaths.length} selected` : 'Select all' }}
                </label>
                <button
                    v-if="fm.trashSelectedPaths.length"
                    type="button"
                    :disabled="fm.trashBulkActionLoading"
                    class="rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-500/10"
                    @click="fm.destroySelectedTrashItems()"
                >
                    <i class="bi bi-trash3 mr-1"></i>{{ fm.trashBulkActionLoading ? 'Deleting…' : `Delete Forever (${fm.trashSelectedPaths.length})` }}
                </button>
            </div>

            <div class="space-y-2">
                <div v-for="item in fm.trashItems" :key="item.trash_path" class="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-2">
                            <input
                                type="checkbox"
                                class="mt-1"
                                :checked="fm.trashSelectedPaths.includes(item.trash_path)"
                                @change="fm.toggleTrashSelection(item.trash_path)"
                            />
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-800 dark:text-slate-100">
                                    <i class="bi mr-1" :class="item.type === 'dir' ? 'bi-folder-fill text-amber-500' : 'bi-file-earmark text-slate-400'"></i>{{ item.file_name }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">From: /{{ item.original_directory || '(root)' }}</p>
                                <p class="text-xs text-slate-400">{{ item.deleted_at }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button
                                type="button"
                                :disabled="fm.trashActionId === item.trash_path"
                                class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-800"
                                @click="fm.restoreTrashItem(item.trash_path)"
                            >
                                <i class="bi bi-arrow-counterclockwise mr-1"></i>Restore
                            </button>
                            <button
                                type="button"
                                :disabled="fm.trashActionId === item.trash_path"
                                class="rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-500/10"
                                @click="fm.destroyTrashItem(item.trash_path)"
                            >
                                <i class="bi bi-trash3 mr-1"></i>Delete Forever
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
