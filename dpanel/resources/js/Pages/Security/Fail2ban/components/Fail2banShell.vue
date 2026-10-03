<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    status: { type: Object, required: true },
    loading: { type: Boolean, default: false },
    loadError: { type: String, default: '' },
    message: { type: Object, default: null },
    refreshing: { type: Boolean, default: false },
});

defineEmits(['refresh']);
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">{{ title }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ description }}</p>
                </div>
                <button type="button" :disabled="refreshing" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('refresh')">
                    {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                </button>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="loadError" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ loadError }}
            </div>
            <div v-else-if="!loading && !status.installed" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                fail2ban is not installed on this server. Install it with <code>sudo dpanel chain install fail2ban</code>.
            </div>
            <div v-else-if="!loading && !status.running" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                fail2ban is installed but not running, so nothing is blocked. Start it with <code>sudo systemctl start fail2ban</code>.
            </div>

            <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="rounded-md border px-4 py-3 text-sm">
                {{ message.text }}
            </div>

            <slot />
        </div>
    </AuthenticatedLayout>
</template>
