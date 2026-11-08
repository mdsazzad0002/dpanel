<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    sizeClass: {
        type: String,
        default: 'w-[304px]',
    },
    src: {
        type: String,
        default: '/dpanel_logo.png',
    },
});

// A reseller's or the server's own logo replaces dPanel's (backlog U4).
const page = usePage();
const custom = computed(() => page.props.branding?.logo_url || '');
const alt = computed(() => `${page.props.branding?.name || 'dPanel'} logo`);
</script>

<template>
    <img v-if="custom" :src="custom" :alt="alt" :class="[sizeClass, 'h-auto max-h-12 object-contain']" />
    <img v-else :src="props.src" :alt="alt" :class="[sizeClass, 'h-auto']" />
</template>
