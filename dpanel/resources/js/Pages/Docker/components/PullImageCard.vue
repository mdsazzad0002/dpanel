<script setup>
import { ref } from 'vue';

defineProps({
    busy: { type: String, default: '' },
});

const emit = defineEmits(['pull', 'prune']);

const image = ref('');

const pull = () => {
    const value = image.value.trim();
    if (!value) return;
    emit('pull', value);
    image.value = '';
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-semibold">Pull an image</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">From Docker Hub (<code>nginx:alpine</code>) or another registry (<code>ghcr.io/org/app:1.2</code>). Large images can take a few minutes.</p>

        <form class="mt-4 grid gap-3 md:grid-cols-[1fr_auto_auto]" @submit.prevent="pull">
            <input v-model="image" type="text" placeholder="nginx:alpine" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" />
            <button type="submit" :disabled="!image.trim() || busy === 'pull'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">
                {{ busy === 'pull' ? 'Pulling…' : 'Pull image' }}
            </button>
            <button type="button" :disabled="busy === 'prune'" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('prune')">
                {{ busy === 'prune' ? 'Cleaning…' : 'Remove unused layers' }}
            </button>
        </form>
    </section>
</template>
