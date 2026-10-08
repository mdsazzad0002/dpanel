<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import DockerSetupGuide from './DockerSetupGuide.vue';

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
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ description }}
                        <span v-if="status.version" class="ml-1 rounded-full border border-slate-200 px-2 py-0.5 text-xs dark:border-slate-700">Docker {{ status.version }}</span>
                    </p>
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
            <DockerSetupGuide v-else-if="!loading && !status.installed" state="missing" :refreshing="refreshing" @refresh="$emit('refresh')" />
            <DockerSetupGuide v-else-if="!loading && !status.running" state="stopped" :refreshing="refreshing" @refresh="$emit('refresh')" />

            <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="whitespace-pre-line rounded-md border px-4 py-3 text-sm">
                {{ message.text }}
            </div>

            <slot v-if="loading || (status.installed && status.running)" />
        </div>
    </AuthenticatedLayout>
</template>
