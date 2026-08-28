<script setup>
import { ref } from 'vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import DocsPanel from '@/Pages/ChatEngine/DocsPanel.vue';

const props = defineProps({
    // Section ids relevant to the page this button lives on — see the
    // catalog in DocsPanel.vue for available ids.
    sections: { type: Array, required: true },
    title: { type: String, default: 'Guide' },
});

const open = ref(false);
</script>

<template>
    <button
        type="button"
        @click="open = true"
        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
    >
        <i class="bi bi-question-circle"></i> Docs
    </button>

    <Offcanvas :show="open" :title="title" subtitle="How this page works" width="lg" @close="open = false">
        <DocsPanel v-if="open" :sections="sections" />
    </Offcanvas>
</template>
