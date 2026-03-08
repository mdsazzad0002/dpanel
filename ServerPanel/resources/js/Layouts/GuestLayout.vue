<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-slate-100 pt-6 text-slate-900 transition-colors dark:bg-slate-950 dark:text-slate-100 sm:justify-center sm:pt-0"
    >
        <div>
            <Link href="/">
                <ApplicationLogo class="h-20 w-20 fill-current text-slate-500 dark:text-slate-300" />
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidden rounded-lg bg-white px-6 py-4 shadow-md ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 sm:max-w-md"
        >
            <slot />
        </div>
    </div>
</template>
