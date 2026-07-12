<script setup>
defineProps({
    toasts: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['dismiss']);
</script>

<template>
    <div class="fixed bottom-4 right-4 z-[60] w-[calc(100vw-2rem)] max-w-sm space-y-3">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            :class="toast.type === 'error'
                ? 'rounded-2xl border border-rose-900/40 bg-rose-950/95 px-4 py-3 text-sm text-rose-100 shadow-2xl backdrop-blur'
                : 'rounded-2xl border border-emerald-900/40 bg-emerald-950/95 px-4 py-3 text-sm text-emerald-100 shadow-2xl backdrop-blur'"
        >
            <div class="flex items-start justify-between gap-3">
                <p class="pr-2 leading-5">{{ toast.message }}</p>
                <button
                    type="button"
                    class="shrink-0 rounded-full p-1 text-current/80 hover:bg-white/10 hover:text-white"
                    aria-label="Dismiss"
                    @click="emit('dismiss', toast.id)"
                >
                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                        <path d="M18.3 5.7a1 1 0 00-1.4-1.4L12 9.17 7.1 4.3A1 1 0 105.7 5.7L10.59 10.6 5.7 15.5a1 1 0 101.4 1.4l4.9-4.89 4.9 4.89a1 1 0 001.4-1.4l-4.89-4.9 4.89-4.9z" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>
