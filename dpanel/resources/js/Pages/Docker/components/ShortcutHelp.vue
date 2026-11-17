<script setup>
import Modal from '@/Components/Modal.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    show: { type: Boolean, default: false },
    // Extra page-specific shortcuts: [{ keys: 'n', label: 'Run a container' }]
    extra: { type: Array, default: () => [] },
});
defineEmits(['close']);

const global = [
    { keys: '?', get label() { return t('docker.shortcuts.show'); } },
    { keys: 'r', get label() { return t('docker.shortcuts.refresh'); } },
    { keys: '/', get label() { return t('docker.shortcuts.search'); } },
    { keys: 'g o', get label() { return t('docker.shortcuts.overview'); } },
    { keys: 'g c', get label() { return t('docker.shortcuts.containers'); } },
    { keys: 'g s', get label() { return t('docker.shortcuts.stacks'); } },
    { keys: 'g t', get label() { return t('docker.shortcuts.templates'); } },
    { keys: 'g i', get label() { return t('docker.shortcuts.images'); } },
    { keys: 'g n', get label() { return t('docker.shortcuts.networks'); } },
    { keys: 'g v', get label() { return t('docker.shortcuts.volumes'); } },
    { keys: 'Esc', get label() { return t('docker.shortcuts.close'); } },
];
</script>

<template>
    <Modal :show="show" max-width="md" @close="$emit('close')">
        <div class="p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">{{ t('docker.shortcuts.title') }}</h2>
                <button type="button" class="rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" :aria-label="t('common.close')" @click="$emit('close')"><i class="bi bi-x-lg"></i></button>
            </div>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('docker.shortcuts.help') }}</p>
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
