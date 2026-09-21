<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    title: { type: String, required: true },
    updatedAt: { type: String, default: '' },
});

const page = usePage();
const appName = computed(() => page.props.app?.name ?? 'dPanel');
</script>

<template>
    <div class="relative min-h-screen bg-[#03060f] text-slate-100">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.14),transparent_35%),radial-gradient(circle_at_bottom_right,rgba(59,130,246,0.14),transparent_30%),linear-gradient(180deg,#040814_0%,#02050a_100%)]"></div>

        <div class="relative z-10 mx-auto flex min-h-screen max-w-3xl flex-col px-4 py-8 sm:px-6 lg:px-8">
            <header class="mb-8 flex items-center justify-between gap-3">
                <Link href="/" class="inline-flex items-center gap-3">
                    <ApplicationLogo class="h-8 fill-current text-emerald-400" />
                    <span class="font-mono text-xs uppercase tracking-[0.3em] text-emerald-200/80">{{ appName }}</span>
                </Link>
                <Link href="/" class="text-sm text-slate-300 hover:text-white">← Back</Link>
            </header>

            <main class="flex-1 rounded-2xl border border-white/10 bg-white/[0.04] p-6 shadow-[0_30px_100px_rgba(0,0,0,0.45)] backdrop-blur-xl sm:p-10">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-50 sm:text-3xl">{{ title }}</h1>
                <p v-if="updatedAt" class="mt-2 text-sm text-slate-400">Last updated: {{ updatedAt }}</p>

                <div class="legal-content mt-8 space-y-6 text-sm leading-7 text-slate-300 sm:text-base">
                    <slot />
                </div>
            </main>

            <footer class="mt-8 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs text-slate-500">
                <Link href="/privacy-policy" class="hover:text-slate-300">Privacy Policy</Link>
                <span>·</span>
                <Link href="/terms-and-conditions" class="hover:text-slate-300">Terms &amp; Conditions</Link>
                <span>·</span>
                <span>&copy; {{ new Date().getFullYear() }} {{ appName }}</span>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.legal-content :deep(h2) {
    margin-top: 2rem;
    font-size: 1.125rem;
    font-weight: 600;
    color: rgb(248 250 252);
}
.legal-content :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}
.legal-content :deep(li) {
    margin-top: 0.375rem;
}
.legal-content :deep(a) {
    color: rgb(52 211 153);
    text-decoration: underline;
}
</style>
