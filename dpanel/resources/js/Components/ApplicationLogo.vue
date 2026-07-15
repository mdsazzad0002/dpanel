<script setup>
defineProps({
    sizeClass: {
        type: String,
        default: 'w-[304px]',
    },
});
</script>

<template>
    <img src="/dpanel_logo.png" alt="dPanel Logo" :class="[sizeClass, 'h-auto']" />
</template>
