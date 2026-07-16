<script setup>
defineOptions({
    layout: null,
});

import Index from './Index.vue';

const props = defineProps({
    website: { type: Object, required: true },
    basePath: { type: String, default: '' },
    rootFolder: { type: String, default: '' },
    currentPath: { type: String, default: '' },
    showHidden: { type: Boolean, default: false },
    openUploadTab: { type: Boolean, default: false },
    openEditorModal: { type: Boolean, default: false },
    openEditorPage: { type: Boolean, default: false },
    directoryTree: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    selectedFile: { type: Object, default: null },
});
</script>

<template>
    <Index v-bind="props" />
</template>
