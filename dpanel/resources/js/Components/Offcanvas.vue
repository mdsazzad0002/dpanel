<script setup>
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    width: { type: String, default: 'md' }, // sm | md | lg | wide
});

const emit = defineEmits(['close']);

const widthClass = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    wide: 'sm:max-w-[70vw]',
}[props.width] || 'sm:max-w-md';

const close = () => emit('close');

const closeOnEscape = (e) => {
    if (e.key === 'Escape' && props.show) close();
};

watch(() => props.show, (value) => {
    document.body.style.overflow = value ? 'hidden' : '';
});

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-50 bg-slate-900/50 dark:bg-black/70" @click="close" />
        </Transition>

        <Transition
            enter-active-class="ease-out duration-200"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="ease-in duration-150"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <div
                v-if="show"
                class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-xl dark:bg-slate-900"
                :class="widthClass"
            >
                <div class="flex items-start justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ title }}</h2>
                        <p v-if="subtitle" class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
                    </div>
                    <button @click="close" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-300">
                        <span class="sr-only">Close</span>
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-5 py-4 text-slate-900 dark:text-slate-100">
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
