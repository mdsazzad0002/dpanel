<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// A second offcanvas stacked over the SQLite viewer (z-50 > its z-40).
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: 'bi-pencil-square' },
});

const emit = defineEmits(['close']);

// Capture phase so Esc closes only this drawer, not the viewer under it.
function onKeydown(event) {
    if (props.open && event.key === 'Escape') {
        event.stopPropagation();
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown, true));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown, true));
</script>

<template>
    <Teleport to="body">
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-200">
            <div v-if="open" class="fixed inset-0 z-50 bg-black/30" @click="emit('close')"></div>
        </Transition>
        <Transition enter-from-class="translate-x-full" leave-to-class="translate-x-full" enter-active-class="transition-transform duration-300 ease-out" leave-active-class="transition-transform duration-200 ease-in">
            <aside v-if="open" class="fixed inset-y-0 right-0 z-50 flex w-full flex-col border-l border-slate-200 bg-white shadow-2xl sm:w-[34rem] dark:border-slate-800 dark:bg-slate-950 text-slate-800 dark:text-slate-200 dark:[color-scheme:dark]">
                <header class="flex items-center gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-500 dark:bg-slate-800">
                        <i class="bi" :class="icon"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{{ title }}</p>
                        <p v-if="subtitle" class="truncate font-mono text-[11px] text-slate-500">{{ subtitle }}</p>
                    </div>
                    <button type="button" :title="t('fileManager.sqlite.closeEsc')" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" @click="emit('close')">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>
                <div class="min-h-0 flex-1 overflow-y-auto p-4">
                    <slot />
                </div>
                <footer v-if="$slots.footer" class="flex items-center gap-2 border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                    <slot name="footer" />
                </footer>
            </aside>
        </Transition>
    </Teleport>
</template>
