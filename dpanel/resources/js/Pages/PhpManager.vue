<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const cards = [
    {
        title: 'PHP Versions',
        short: 'Default',
        description: 'Set the active PHP versions and choose the default runtime version.',
        href: route('php.versions'),
        accent: 'from-blue-500/20 to-cyan-500/10 border-blue-300 dark:border-blue-800',
        badge: 'Versions',
    },
    {
        title: 'PHP Config',
        short: 'Config',
        description: 'Edit php.ini style runtime values for each PHP version.',
        href: route('php.config'),
        accent: 'from-emerald-500/20 to-teal-500/10 border-emerald-300 dark:border-emerald-800',
        badge: 'Config',
    },
    {
        title: 'PHP Extensions',
        short: 'Modules',
        description: 'Enable or disable PHP modules for each installed version.',
        href: route('php.extensions'),
        accent: 'from-amber-500/20 to-orange-500/10 border-amber-300 dark:border-amber-800',
        badge: 'Extensions',
    },
];
</script>

<template>
    <Head title="PHP Manager" />

    <AuthenticatedLayout>
        <template #header>
            <div class="space-y-1">
                <h1 class="text-lg font-semibold">PHP Manager</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Choose what you want to manage.</p>
            </div>
        </template>

        <div class="space-y-5">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-4 md:grid-cols-3">
                    <Link
                        v-for="card in cards"
                        :key="card.title"
                        :href="card.href"
                        class="group flex h-full flex-col justify-between rounded-2xl border bg-gradient-to-br p-5 transition duration-200 hover:-translate-y-0.5 hover:shadow-lg"
                        :class="card.accent"
                    >
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="rounded-full border border-white/20 bg-slate-900/80 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-white dark:bg-black/40">
                                    {{ card.badge }}
                                </span>
                                <span class="text-xs font-medium text-slate-500 dark:text-slate-300">{{ card.short }}</span>
                            </div>
                            <div class="space-y-2">
                                <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ card.title }}</h2>
                                <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">{{ card.description }}</p>
                            </div>
                        </div>

                        <div class="mt-6 inline-flex items-center gap-2 text-sm font-medium text-slate-900 transition group-hover:translate-x-1 dark:text-slate-100">
                            Open
                            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M6 3l5 5-5 5V3z" />
                            </svg>
                        </div>
                    </Link>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
