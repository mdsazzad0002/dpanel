<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { previewKind } from '../helpers/previewTypes';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    fm: {
        type: Object,
        required: true,
    },
});

const loadError = ref(false);
const loading = ref(true);

const item = computed(() => props.fm.previewItem);
const kind = computed(() => (item.value ? previewKind(item.value.name) : null));

// Every previewable file in the current listing, for prev/next stepping.
const siblings = computed(() => (props.fm.filteredItems || [])
    .filter((entry) => entry?.type === 'file' && previewKind(entry.name)));
const index = computed(() => siblings.value.findIndex((entry) => entry.path === item.value?.path));

function fileUrl(inline) {
    if (!item.value) return '';
    const params = { file_path: item.value.path };
    if (inline) params.inline = 1;
    return props.fm.panelRoute('websites.filemanager.file.download', props.fm.fileManagerRouteParams(params));
}

const inlineUrl = computed(() => fileUrl(true));
const downloadUrl = computed(() => fileUrl(false));

watch(() => item.value?.path, () => {
    loadError.value = false;
    // Audio/video report readiness through their own controls.
    loading.value = kind.value === 'image' || kind.value === 'pdf';
});

function close() {
    props.fm.closePreview();
}

function step(offset) {
    const list = siblings.value;
    if (list.length < 2 || index.value < 0) return;
    const next = list[(index.value + offset + list.length) % list.length];
    props.fm.openPreview(next);
}

function onKeydown(event) {
    if (!item.value) return;
    if (event.key === 'Escape') close();
    else if (event.key === 'ArrowRight') step(1);
    else if (event.key === 'ArrowLeft') step(-1);
}

function onLoaded() {
    loading.value = false;
}

function onError() {
    loading.value = false;
    loadError.value = true;
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-200">
        <div v-if="item" class="fixed inset-0 z-40 bg-black/40" @click="close"></div>
    </Transition>
    <Transition enter-from-class="translate-x-full" leave-to-class="translate-x-full" enter-active-class="transition-transform duration-300 ease-out" leave-active-class="transition-transform duration-200 ease-in">
        <aside
            v-if="item"
            class="fixed inset-y-0 right-0 z-40 flex w-full flex-col border-l border-slate-200 bg-white shadow-2xl md:w-[60vw] xl:w-[50vw] dark:border-slate-800 dark:bg-slate-950 text-slate-800 dark:text-slate-200 dark:[color-scheme:dark]"
        >
            <header class="flex items-center gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800">
                    <i class="bi" :class="fm.iconClassForItem(item)"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-800 dark:text-slate-100" :title="item.name">{{ item.name }}</p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        {{ fm.formatBytes(item.size) }}
                        <span v-if="siblings.length > 1 && index >= 0"> &middot; {{ index + 1 }} / {{ siblings.length }}</span>
                    </p>
                </div>
                <a
                    :href="inlineUrl"
                    target="_blank"
                    rel="noopener"
                    class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    :title="t('fileManager.preview.openNewTab')"
                >
                    <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <a
                    :href="downloadUrl"
                    class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    :title="t('fileManager.preview.download')"
                >
                    <i class="bi bi-download"></i>
                </a>
                <button type="button" class="rounded-md px-2 py-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-white" :title="t('fileManager.preview.close')" @click="close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>

            <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-auto bg-[repeating-conic-gradient(#f1f5f9_0%_25%,#ffffff_0%_50%)] bg-[length:20px_20px] p-4 dark:bg-[repeating-conic-gradient(#0f172a_0%_25%,#020617_0%_50%)]">
                <div v-if="loading && !loadError" class="absolute inset-0 flex items-center justify-center text-slate-400">
                    <i class="bi bi-arrow-repeat animate-spin text-2xl"></i>
                </div>

                <div v-if="loadError" class="text-center text-sm text-slate-500">
                    <i class="bi bi-exclamation-triangle mb-2 block text-3xl text-amber-500"></i>
                    {{ t('fileManager.preview.failed') }}
                    <a :href="downloadUrl" class="mt-2 block text-sky-600 underline dark:text-sky-400">{{ t('fileManager.preview.downloadInstead') }}</a>
                </div>

                <template v-else>
                    <img
                        v-if="kind === 'image'"
                        :key="inlineUrl"
                        :src="inlineUrl"
                        :alt="item.name"
                        class="max-h-full max-w-full object-contain shadow"
                        @load="onLoaded"
                        @error="onError"
                    >
                    <video
                        v-else-if="kind === 'video'"
                        :key="inlineUrl"
                        :src="inlineUrl"
                        controls
                        autoplay
                        class="max-h-full max-w-full rounded bg-black shadow"
                        @error="onError"
                    ></video>
                    <div v-else-if="kind === 'audio'" class="w-full max-w-lg rounded-xl bg-white/90 p-6 text-center shadow dark:bg-slate-900/90">
                        <i class="bi bi-music-note-beamed mb-4 block text-5xl text-sky-500"></i>
                        <audio :key="inlineUrl" :src="inlineUrl" controls autoplay class="w-full" @error="onError"></audio>
                    </div>
                    <iframe
                        v-else-if="kind === 'pdf'"
                        :key="inlineUrl"
                        :src="inlineUrl"
                        :title="item.name"
                        class="h-full w-full rounded border-0 bg-white"
                        @load="onLoaded"
                    ></iframe>
                </template>

                <template v-if="siblings.length > 1">
                    <button type="button" class="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2.5 text-white hover:bg-black/60" :title="t('fileManager.preview.previous')" @click="step(-1)">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2.5 text-white hover:bg-black/60" :title="t('fileManager.preview.next')" @click="step(1)">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </template>
            </div>
        </aside>
    </Transition>
</template>
