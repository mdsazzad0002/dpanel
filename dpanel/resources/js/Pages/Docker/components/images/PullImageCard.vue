<script setup>
import { ref } from 'vue';

defineProps({
    busy: { type: String, default: '' },
});

const emit = defineEmits(['pull', 'prune']);

const image = ref('');
const popular = ['nginx:alpine', 'redis:7-alpine', 'postgres:16-alpine', 'mysql:8.4', 'mariadb:11', 'mongo:7', 'node:22-alpine', 'python:3.12-slim'];

const pull = (value = image.value) => {
    const name = value.trim();
    if (!name) return;
    emit('pull', name);
    image.value = '';
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-semibold">Pull an image</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">From Docker Hub (<code>nginx:alpine</code>) or another registry (<code>ghcr.io/org/app:1.2</code>). Large images can take a few minutes.</p>

        <form class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="pull()">
            <input v-model="image" type="text" placeholder="nginx:alpine" autocomplete="off" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" />
            <button type="submit" :disabled="!image.trim() || busy.startsWith('pull:')" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">
                {{ busy.startsWith('pull:') ? 'Pulling…' : 'Pull image' }}
            </button>
        </form>
        <div class="mt-3 flex flex-wrap items-center gap-1">
            <span class="mr-1 text-xs text-slate-500">Popular:</span>
            <button v-for="p in popular" :key="p" type="button" :disabled="busy.startsWith('pull:')" class="rounded-full border border-slate-300 px-2.5 py-0.5 font-mono text-[11px] hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="pull(p)">{{ p }}</button>
        </div>

        <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
            <button type="button" :disabled="busy === 'prune'" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('prune', false)">
                <i class="bi bi-eraser mr-1"></i>{{ busy === 'prune' ? 'Cleaning…' : 'Remove untagged layers' }}
            </button>
            <button type="button" :disabled="busy === 'prune-all'" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('prune', true)">
                <i class="bi bi-trash3 mr-1"></i>{{ busy === 'prune-all' ? 'Cleaning…' : 'Remove all unused images' }}
            </button>
        </div>
    </section>
</template>
