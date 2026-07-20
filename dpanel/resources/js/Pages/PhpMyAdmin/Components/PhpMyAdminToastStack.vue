<script setup>
defineProps({
    toasts: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['dismiss']);

const typeStyles = {
    error: {
        bg: 'bg-rose-950/95',
        border: 'border-rose-900/40',
        text: 'text-rose-100',
        icon: 'bi-exclamation-circle-fill',
        iconColor: 'text-rose-400',
        progress: 'bg-rose-500',
    },
    success: {
        bg: 'bg-emerald-950/95',
        border: 'border-emerald-900/40',
        text: 'text-emerald-100',
        icon: 'bi-check-circle-fill',
        iconColor: 'text-emerald-400',
        progress: 'bg-emerald-500',
    },
    warning: {
        bg: 'bg-amber-950/95',
        border: 'border-amber-900/40',
        text: 'text-amber-100',
        icon: 'bi-exclamation-triangle-fill',
        iconColor: 'text-amber-400',
        progress: 'bg-amber-500',
    },
    info: {
        bg: 'bg-blue-950/95',
        border: 'border-blue-900/40',
        text: 'text-blue-100',
        icon: 'bi-info-circle-fill',
        iconColor: 'text-blue-400',
        progress: 'bg-blue-500',
    },
};

const getStyles = (type) => typeStyles[type] || typeStyles.info;
</script>

<template>
    <div class="fixed bottom-4 right-4 z-[60] w-[calc(100vw-2rem)] max-w-sm space-y-2">
        <TransitionGroup
            name="toast"
            tag="div"
            class="space-y-2"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                :class="[
                    'relative overflow-hidden rounded-xl border px-4 py-3 text-sm shadow-2xl backdrop-blur',
                    getStyles(toast.type).bg,
                    getStyles(toast.type).border,
                    getStyles(toast.type).text,
                ]"
            >
                <div class="flex items-start gap-3">
                    <i :class="['bi mt-0.5 text-base', getStyles(toast.type).icon, getStyles(toast.type).iconColor]"></i>
                    <p class="flex-1 pr-2 leading-5">{{ toast.message }}</p>
                    <button
                        type="button"
                        class="shrink-0 rounded-full p-1 text-current/60 transition-colors hover:bg-white/10 hover:text-current"
                        aria-label="Dismiss"
                        @click="emit('dismiss', toast.id)"
                    >
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current" aria-hidden="true">
                            <path d="M18.3 5.7a1 1 0 00-1.4-1.4L12 9.17 7.1 4.3A1 1 0 105.7 5.7L10.59 10.6 5.7 15.5a1 1 0 101.4 1.4l4.9-4.89 4.9 4.89a1 1 0 001.4-1.4l-4.89-4.9 4.89-4.9z" />
                        </svg>
                    </button>
                </div>
                <!-- Progress bar -->
                <div class="absolute bottom-0 left-0 h-0.5 w-full bg-white/10">
                    <div :class="['h-full animate-progress', getStyles(toast.type).progress]"></div>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active {
    transition: all 0.3s cubic-bezier(0.21, 1.02, 0.73, 1);
}

.toast-leave-active {
    transition: all 0.25s cubic-bezier(0.21, 1.02, 0.73, 1);
}

.toast-enter-from {
    opacity: 0;
    transform: translateX(100%) scale(0.95);
}

.toast-leave-to {
    opacity: 0;
    transform: translateX(100%) scale(0.95);
}

.toast-move {
    transition: transform 0.3s ease;
}

@keyframes progress {
    from { width: 100%; }
    to { width: 0%; }
}

.animate-progress {
    animation: progress 4.5s linear forwards;
}
</style>
