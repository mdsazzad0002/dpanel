<script setup>
defineProps({
    toasts: {
        type: Array,
        default: () => [],
    },
});

defineEmits(['dismiss']);
</script>

<template>
    <div class="fixed bottom-4 right-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-3">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-[-8px] opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-[-8px] opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-start gap-3 rounded-2xl border bg-white px-4 py-3 shadow-lg dark:bg-slate-900"
                :class="toast.type === 'success'
                    ? 'border-emerald-200 text-emerald-700 dark:border-emerald-800 dark:text-emerald-400'
                    : 'border-red-200 text-red-700 dark:border-red-800 dark:text-red-400'"
            >
                <div
                    class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl"
                    :class="toast.type === 'success' ? 'bg-emerald-500/10' : 'bg-red-500/10'"
                >
                    <svg v-if="toast.type === 'success'" viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium leading-5 text-slate-900 dark:text-slate-100">
                        {{ toast.message }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg p-1 text-slate-400 transition hover:text-slate-700 dark:hover:text-slate-200"
                    @click="$emit('dismiss', toast.id)"
                    aria-label="Dismiss notification"
                >
                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                        <path d="M18.3 5.71 12 12.01l-6.3-6.3-1.41 1.41 6.3 6.3-6.3 6.29 1.41 1.41 6.3-6.3 6.29 6.3 1.41-1.41-6.3-6.29 6.3-6.3z" />
                    </svg>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
