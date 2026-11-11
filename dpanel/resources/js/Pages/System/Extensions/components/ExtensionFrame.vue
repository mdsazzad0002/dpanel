<script setup>
// The extension's admin page (U7). The attributes come from the server and
// are bound as given: `sandbox` never has allow-same-origin, so the page runs
// in an opaque origin and cannot reach the panel's cookies, storage or DOM.
defineProps({
    frame: { type: Object, required: true },
    title: { type: String, default: '' },
});
</script>

<template>
    <iframe
        :src="frame.src"
        :sandbox="frame.sandbox"
        :referrerpolicy="frame.referrerpolicy"
        :allow="frame.allow"
        :title="title"
        loading="lazy"
        class="h-[75vh] w-full rounded-2xl border border-slate-200 bg-white dark:border-slate-800"
    />
</template>
