<script setup>
import Modal from '@/Components/Modal.vue';

defineProps({
    show: { type: Boolean, default: false },
    // Extra page-specific shortcuts: [{ keys: 'n', label: 'Run a container' }]
    extra: { type: Array, default: () => [] },
});
defineEmits(['close']);

const global = [
    { keys: '?', label: 'Show this list' },
    { keys: 'r', label: 'Refresh' },
    { keys: '/', label: 'Search on this page' },
    { keys: 'g o', label: 'Go to Overview' },
    { keys: 'g c', label: 'Go to Containers' },
    { keys: 'g s', label: 'Go to Stacks' },
    { keys: 'g t', label: 'Go to App templates' },
    { keys: 'g i', label: 'Go to Images' },
    { keys: 'g n', label: 'Go to Networks' },
    { keys: 'g v', label: 'Go to Volumes' },
    { keys: 'Esc', label: 'Close a dialog' },
];
</script>

<template>
    <Modal :show="show" max-width="md" @close="$emit('close')">
        <div class="p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Keyboard shortcuts</h2>
                <button type="button" class="rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Close" @click="$emit('close')"><i class="bi bi-x-lg"></i></button>
            </div>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">They work anywhere on the Docker pages, except while typing in a field.</p>
            <dl class="mt-4 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <div v-for="item in [...extra, ...global]" :key="item.keys + item.label" class="flex items-center justify-between gap-4 py-2">
                    <dt class="text-slate-600 dark:text-slate-300">{{ item.label }}</dt>
                    <dd class="flex gap-1">
                        <kbd v-for="k in item.keys.split(' ')" :key="k" class="min-w-[1.75rem] rounded border border-slate-300 bg-slate-50 px-1.5 py-0.5 text-center font-mono text-xs dark:border-slate-600 dark:bg-slate-800">{{ k }}</kbd>
                    </dd>
                </div>
            </dl>
        </div>
    </Modal>
</template>
