<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

const page = usePage();
const dashboardStats = computed(() => page.props.dashboardStats ?? {});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Server Dashboard</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Basic structure for hosting and server management.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU Load</p>
                    <p class="mt-2 text-2xl font-semibold">{{ dashboardStats.cpu_load_percent ?? 0 }}%</p>
                    <p class="mt-1 text-xs text-emerald-600">{{ (dashboardStats.cpu_load_percent ?? 0) > 80 ? 'High' : 'Normal' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Memory</p>
                    <p class="mt-2 text-2xl font-semibold">{{ dashboardStats.memory_used_mb ?? 0 }} MB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">of {{ dashboardStats.memory_total_mb ?? 0 }} MB</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Websites</p>
                    <p class="mt-2 text-2xl font-semibold">{{ dashboardStats.websites_total ?? 0 }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ dashboardStats.websites_pending ?? 0 }} pending requests</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Mail Queue</p>
                    <p class="mt-2 text-2xl font-semibold">{{ dashboardStats.mail_queue ?? 0 }}</p>
                    <p class="mt-1 text-xs text-amber-600">{{ (dashboardStats.mail_queue ?? 0) > 0 ? 'Needs review' : 'Clean' }}</p>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-5 xl:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Services</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">Apache</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Status: {{ dashboardStats.services?.apache || 'unknown' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">Mail Server</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Postfix: {{ dashboardStats.services?.mail || 'unknown' }}, Dovecot: {{ dashboardStats.services?.dovecot || 'unknown' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">MySQL/MariaDB</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Driver/Service: {{ dashboardStats.services?.database || 'unknown' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">Redis</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Status: {{ dashboardStats.services?.redis || 'unknown' }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Quick Actions</h2>
                    <div class="mt-4 space-y-2">
                        <a :href="route('websites.create')" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Create Website</a>
                        <a :href="route('emails.create')" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Add Mailbox</a>
                        <a :href="route('terminal.index')" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Open Terminal</a>
                        <a :href="route('websites.list')" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Manage Websites</a>
                        <a href="/" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Installer Setup Guide</a>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
