<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Link
        :href="href"
        class="flex w-full items-center px-4 py-2 text-start text-sm text-slate-700 transition-colors duration-150 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none dark:text-slate-300 dark:hover:bg-slate-800 dark:focus:bg-slate-800"
    >
        <slot />
    </Link>
</template>
