<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="relative min-h-screen flex  overflow-hidden bg-[#03060f] text-slate-100">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.16),transparent_35%),radial-gradient(circle_at_bottom_right,rgba(59,130,246,0.16),transparent_30%),linear-gradient(180deg,#040814_0%,#02050a_100%)]"></div>
        <div class="absolute inset-0 opacity-40 [background-image:linear-gradient(rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.03)_1px,transparent_1px)] [background-size:72px_72px]"></div>

        <div class="relative z-10 mx-auto flex items-center  w-full max-w-7xl items-center px-4 py-6 sm:px-6 lg:px-8">
            <div class="grid w-full overflow-hidden rounded-[2rem] border border-white/10 bg-white/[0.04] shadow-[0_30px_100px_rgba(0,0,0,0.45)] backdrop-blur-xl  lg:grid-cols-[1.05fr_0.95fr]">
                <div class="relative hidden overflow-hidden border-b border-white/10 bg-[linear-gradient(160deg,rgba(4,18,15,0.98),rgba(4,10,20,0.92))] px-8 py-10 lg:flex lg:flex-col lg:justify-between lg:border-b-0 lg:border-r lg:px-10 lg:py-12">
                    <div class="absolute -left-24 top-10 h-56 w-56 rounded-full bg-emerald-500/20 blur-3xl"></div>
                    <div class="absolute bottom-0 right-0 h-72 w-72 rounded-full bg-sky-500/10 blur-3xl"></div>

                    <div class="relative flex items-center gap-3">
                        <Link href="/" class="inline-flex items-center gap-3">
                            <ApplicationLogo class="fill-current text-emerald-400" />
                        </Link>
                        <div class="h-px flex-1 bg-gradient-to-r from-emerald-400/50 via-white/10 to-transparent"></div>
                    </div>

                    <div class="relative max-w-xl">

                        <h1 class="mt-5 max-w-lg text-4xl font-semibold leading-tight tracking-tight text-slate-50 xl:text-5xl">
                            Secure access for server operations
                        </h1>
                        <p class="mt-5 max-w-lg text-base leading-7 text-slate-300">
                            A focused admin surface for production control, recovery, and daily maintenance with a cleaner visual hierarchy.
                        </p>
                    </div>

                    <div class="relative grid gap-4 text-sm text-slate-200/80 sm:grid-cols-2">
                        <div class="rounded-2xl border border-white/10 bg-white/[0.05] p-4 shadow-lg shadow-black/10">
                            <p class="font-mono text-xs uppercase tracking-[0.22em] text-emerald-300">Operations</p>
                            <p class="mt-2 leading-6">Centralized tools for production workflows and quick recovery.</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/[0.05] p-4 shadow-lg shadow-black/10">
                            <p class="font-mono text-xs uppercase tracking-[0.22em] text-sky-300">Security</p>
                            <p class="mt-2 leading-6">Protected access with a calm login flow and password checks.</p>
                        </div>
                    </div>
                </div>

                <div class="flex min-h-[34rem] w-full items-center justify-center px-5 py-8 sm:px-8 lg:px-10 lg:py-12">
                    <div class="w-full max-w-md">
                        <div class="mb-8 flex items-center justify-between gap-3 lg:hidden">
                            <Link href="/" class="inline-flex items-center gap-3">
                                <ApplicationLogo class="fill-current text-emerald-400" />
                                <span class="font-mono text-xs uppercase tracking-[0.3em] text-emerald-200/80">
                                    dPanel
                                </span>
                            </Link>
                        </div>

                        <div class="">
                            <slot />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
