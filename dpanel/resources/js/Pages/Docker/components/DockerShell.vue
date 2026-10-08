<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DockerSetupGuide from './DockerSetupGuide.vue';
import DockerNav from './DockerNav.vue';
import { sections } from '../composables/sections';
import ShortcutHelp from './ShortcutHelp.vue';
import { useShortcuts } from '../composables/useShortcuts';

const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    status: { type: Object, required: true },
    loading: { type: Boolean, default: false },
    loadError: { type: String, default: '' },
    message: { type: Object, default: null },
    refreshing: { type: Boolean, default: false },
    // Page shortcuts for the help list; the page handles them through `keys`.
    shortcuts: { type: Array, default: () => [] },
    keys: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['refresh', 'dismiss']);

const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const helpOpen = ref(false);

const go = (name) => router.visit(token.value ? route(name, { token: token.value }) : route(name));

useShortcuts({
    '?': () => { helpOpen.value = true; },
    r: () => emit('refresh'),
    '/': () => document.querySelector('[data-docker-search]')?.focus(),
    ...Object.fromEntries(sections.map((s) => [`g ${s.key}`, () => go(s.route)])),
    ...props.keys,
});
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h1 class="text-lg font-semibold">{{ title }}</h1>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ description }}
                            <span v-if="status.version" class="ml-1 whitespace-nowrap rounded-full border border-slate-200 px-2 py-0.5 text-xs dark:border-slate-700">Docker {{ status.version }}</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <slot name="actions" />
                        <button type="button" class="hidden rounded-md border border-slate-300 px-2.5 py-2 text-xs hover:bg-slate-100 sm:inline-block dark:border-slate-700 dark:hover:bg-slate-800" title="Keyboard shortcuts (?)" @click="helpOpen = true">
                            <i class="bi bi-keyboard"></i>
                        </button>
                        <button type="button" :disabled="refreshing" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" title="Refresh (r)" @click="$emit('refresh')">
                            <i class="bi bi-arrow-clockwise" :class="refreshing ? 'inline-block animate-spin' : ''"></i>
                            <span class="ml-1">{{ refreshing ? 'Refreshing…' : 'Refresh' }}</span>
                        </button>
                    </div>
                </div>
                <DockerNav v-if="status.installed && status.running" />
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="loadError" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ loadError }}
            </div>
            <DockerSetupGuide v-else-if="!loading && !status.installed" state="missing" :refreshing="refreshing" @refresh="$emit('refresh')" />
            <DockerSetupGuide v-else-if="!loading && !status.running" state="stopped" :refreshing="refreshing" @refresh="$emit('refresh')" />

            <div v-if="message" role="status" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="flex items-start gap-3 whitespace-pre-line rounded-md border px-4 py-3 text-sm">
                <span class="min-w-0 flex-1 break-words">{{ message.text }}</span>
                <button type="button" class="shrink-0 opacity-60 hover:opacity-100" aria-label="Dismiss" @click="$emit('dismiss')"><i class="bi bi-x-lg"></i></button>
            </div>

            <slot v-if="loading || (status.installed && status.running)" />
        </div>

        <ShortcutHelp :show="helpOpen" :extra="shortcuts" @close="helpOpen = false" />
    </AuthenticatedLayout>
</template>
