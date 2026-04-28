<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="relative flex min-h-screen flex-col items-center overflow-hidden bg-[#05070d] px-4 pt-8 text-slate-100 transition-colors sm:justify-center sm:pt-0"
    >
        <div class="pointer-events-none absolute inset-0 opacity-20 [background-image:linear-gradient(to_right,#10b98122_1px,transparent_1px),linear-gradient(to_bottom,#10b98122_1px,transparent_1px)] [background-size:28px_28px]" />
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,#052e26_0%,transparent_45%)]" />

        <div>
            <Link href="/">
                <ApplicationLogo class="h-20 w-20 fill-current text-emerald-400" />
            </Link>
        </div>

        <div
            class="z-10 mt-6 w-full max-w-lg overflow-hidden"
        >
            <slot />
        </div>
    </div>
</template>
