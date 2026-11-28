<script setup>
// The search window. One bar: it looks for file names by default, and the
// settings row under it switches the same bar to searching the text inside
// files. The walk itself runs on the server (drust), so it covers the whole
// account rather than only what has been browsed.
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { iconClassForItem } from '../helpers/fileIcons';
import { highlightSegments } from '../helpers/fuzzySearch';

const { t } = useI18n();

const props = defineProps({
    fm: {
        type: Object,
        required: true,
    },
});

const input = ref(null);
const listEl = ref(null);

const GROUP_LABELS = {
    open: 'fileManager.palette.groupOpen',
    recent: 'fileManager.palette.groupRecent',
    file: 'fileManager.palette.groupFiles',
    dir: 'fileManager.palette.groupFolders',
};

/** Rows with a heading wherever the group — or, in text mode, the file — changes. */
const rows = computed(() => {
    const out = [];
    let heading = null;

    props.fm.paletteResults.forEach((entry, index) => {
        const key = entry.group === 'text' ? entry.path : entry.group;
        if (key !== heading) {
            heading = key;
            out.push({
                key: `h-${key}-${index}`,
                headingPath: entry.group === 'text' ? entry.path : '',
                heading: entry.group === 'text' ? '' : (GROUP_LABELS[entry.group] || GROUP_LABELS.file),
            });
        }

        out.push({ entry, index, key: `${entry.path}:${entry.line || 0}:${index}` });
    });

    return out;
});

function folderOf(path) {
    const parts = String(path || '').split('/').filter(Boolean);
    parts.pop();

    return parts.join('/') || '/';
}

function nameSegments(entry) {
    return highlightSegments(entry.name, entry.ranges);
}

/** A matching line, split around the hit the server reported. */
function lineSegments(entry) {
    const text = String(entry.text || '');
    const start = Math.max(0, (entry.column || 1) - 1);
    const end = Math.min(text.length, start + (entry.length || 0));
    if (!entry.length || start >= text.length) return [{ text, hit: false }];

    return [
        { text: text.slice(0, start), hit: false },
        { text: text.slice(start, end), hit: true },
        { text: text.slice(end), hit: false },
    ];
}

function setOption(key, value) {
    props.fm.paletteOptions = { ...props.fm.paletteOptions, [key]: value };
}

const TOGGLE_ON = 'border-sky-500/60 bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300';
const TOGGLE_OFF = 'border-slate-200 text-slate-500 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800';

watch(() => props.fm.paletteOpen, async (open) => {
    if (!open) return;

    await nextTick();
    input.value?.focus();
});

watch(() => props.fm.paletteCursor, async () => {
    await nextTick();
    listEl.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0"
        leave-active-class="transition duration-100 ease-in"
        leave-to-class="opacity-0"
    >
        <div
            v-if="fm.paletteOpen"
            class="fixed inset-0 z-[70] flex items-start justify-center bg-slate-900/40 px-4 pt-[10vh] backdrop-blur-sm"
            @click="fm.closePalette()"
        >
            <div
                class="w-full max-w-2xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
                role="dialog"
                aria-modal="true"
                @click.stop
            >
                <div class="flex items-center gap-3 px-4">
                    <i class="bi text-sm text-slate-400" :class="fm.paletteMode === 'content' ? 'bi-file-earmark-text' : 'bi-search'"></i>
                    <input
                        ref="input"
                        v-model="fm.paletteQuery"
                        type="text"
                        class="w-full bg-transparent py-4 text-sm outline-none placeholder:text-slate-400 dark:text-slate-100"
                        :placeholder="fm.paletteMode === 'content' ? t('fileManager.palette.placeholderText') : t('fileManager.palette.placeholder')"
                        spellcheck="false"
                        autocomplete="off"
                        @keydown.down.prevent="fm.movePaletteCursor(1)"
                        @keydown.up.prevent="fm.movePaletteCursor(-1)"
                        @keydown.enter.prevent="fm.runPaletteEntry()"
                        @keydown.esc.prevent="fm.closePalette()"
                    >
                    <i v-if="fm.paletteLoading" class="bi bi-arrow-repeat animate-spin text-xs text-slate-400"></i>
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-400 transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        @click="fm.closePalette()"
                    >esc</button>
                </div>

                <!-- The settings row: what the one bar above searches. -->
                <div class="flex flex-wrap items-center gap-2 border-y border-slate-200 bg-slate-50/70 px-4 py-2 text-[11px] dark:border-slate-700 dark:bg-slate-800/40">
                    <div class="flex overflow-hidden rounded-md border border-slate-200 dark:border-slate-700">
                        <button
                            type="button"
                            class="px-2.5 py-1 transition"
                            :class="fm.paletteMode === 'names' ? 'bg-sky-600 font-medium text-white' : 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'"
                            @click="fm.setPaletteMode('names')"
                        >
                            <i class="bi bi-file-earmark mr-1"></i>{{ t('fileManager.palette.modeFiles') }}
                        </button>
                        <button
                            type="button"
                            class="px-2.5 py-1 transition"
                            :class="fm.paletteMode === 'content' ? 'bg-sky-600 font-medium text-white' : 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'"
                            @click="fm.setPaletteMode('content')"
                        >
                            <i class="bi bi-body-text mr-1"></i>{{ t('fileManager.palette.modeText') }}
                        </button>
                    </div>

                    <button
                        type="button"
                        class="rounded-md border px-2 py-1 transition"
                        :class="fm.paletteScope === 'folder' ? TOGGLE_ON : TOGGLE_OFF"
                        :title="t('fileManager.palette.scopeFolderHint')"
                        @click="fm.paletteScope = fm.paletteScope === 'folder' ? 'root' : 'folder'"
                    >
                        <i class="bi bi-folder mr-1"></i>{{ t('fileManager.palette.scopeFolder') }}
                    </button>

                    <template v-if="fm.paletteMode === 'content'">
                        <button
                            type="button"
                            class="rounded-md border px-2 py-1 font-semibold transition"
                            :class="fm.paletteOptions.caseSensitive ? TOGGLE_ON : TOGGLE_OFF"
                            :title="t('fileManager.palette.caseSensitive')"
                            @click="setOption('caseSensitive', !fm.paletteOptions.caseSensitive)"
                        >Aa</button>
                        <button
                            type="button"
                            class="rounded-md border px-2 py-1 transition"
                            :class="fm.paletteOptions.wholeWord ? TOGGLE_ON : TOGGLE_OFF"
                            :title="t('fileManager.palette.wholeWord')"
                            @click="setOption('wholeWord', !fm.paletteOptions.wholeWord)"
                        ><span class="underline">ab</span></button>
                        <input
                            :value="fm.paletteOptions.include"
                            type="text"
                            class="w-28 rounded-md border border-slate-200 bg-white px-2 py-1 outline-none focus:border-sky-400 dark:border-slate-700 dark:bg-slate-900"
                            :placeholder="t('fileManager.palette.includePlaceholder')"
                            :title="t('fileManager.palette.includeHint')"
                            spellcheck="false"
                            @input="setOption('include', $event.target.value)"
                        >
                    </template>

                    <button
                        type="button"
                        class="rounded-md border px-2 py-1 transition"
                        :class="fm.paletteOptions.includeIgnored ? TOGGLE_ON : TOGGLE_OFF"
                        :title="t('fileManager.palette.includeIgnoredHint')"
                        @click="setOption('includeIgnored', !fm.paletteOptions.includeIgnored)"
                    >
                        <i class="bi bi-eye-slash mr-1"></i>{{ t('fileManager.palette.includeIgnored') }}
                    </button>
                </div>

                <div ref="listEl" class="max-h-[50vh] overflow-y-auto py-2">
                    <template v-for="row in rows" :key="row.key">
                        <p
                            v-if="row.heading"
                            class="px-4 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 first:pt-1"
                        >{{ t(row.heading) }}</p>
                        <p
                            v-else-if="row.headingPath"
                            class="truncate px-4 pb-1 pt-3 font-mono text-[11px] text-slate-500 first:pt-1 dark:text-slate-400"
                        >{{ row.headingPath }}</p>

                        <button
                            v-else
                            type="button"
                            class="flex w-full items-center gap-3 px-4 py-2 text-left transition"
                            :data-active="row.index === fm.paletteCursor"
                            :class="row.index === fm.paletteCursor
                                ? 'bg-sky-50 dark:bg-sky-500/10'
                                : 'hover:bg-slate-50 dark:hover:bg-slate-800/60'"
                            @mousemove="fm.paletteCursor = row.index"
                            @click="fm.runPaletteEntry(row.entry)"
                        >
                            <template v-if="row.entry.line">
                                <span class="w-10 shrink-0 text-right font-mono text-[11px] text-slate-400">{{ row.entry.line }}</span>
                                <span class="min-w-0 flex-1 truncate font-mono text-xs text-slate-700 dark:text-slate-200">
                                    <span
                                        v-for="(segment, segmentIndex) in lineSegments(row.entry)"
                                        :key="segmentIndex"
                                        :class="segment.hit ? 'rounded-sm bg-amber-200/70 font-semibold text-slate-900 dark:bg-amber-500/30 dark:text-amber-100' : ''"
                                    >{{ segment.text }}</span>
                                </span>
                            </template>

                            <template v-else>
                                <i class="bi shrink-0 text-sm" :class="iconClassForItem(row.entry)"></i>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-slate-800 dark:text-slate-100">
                                        <span
                                            v-for="(segment, segmentIndex) in nameSegments(row.entry)"
                                            :key="segmentIndex"
                                            :class="segment.hit ? 'font-semibold text-sky-600 dark:text-sky-400' : ''"
                                        >{{ segment.text }}</span>
                                    </span>
                                    <span class="block truncate text-[11px] text-slate-400">{{ folderOf(row.entry.path) }}</span>
                                </span>
                                <span v-if="fm.isTabDirty(row.entry.path)" class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400"></span>
                            </template>

                            <i v-if="row.index === fm.paletteCursor" class="bi bi-arrow-return-left shrink-0 text-xs text-slate-400"></i>
                        </button>
                    </template>

                    <p v-if="fm.paletteError" class="px-4 py-8 text-center text-sm text-red-600 dark:text-red-400">{{ fm.paletteError }}</p>
                    <p v-else-if="!rows.length && fm.paletteLoading" class="px-4 py-10 text-center text-sm text-slate-400">{{ t('fileManager.palette.searching') }}</p>
                    <p v-else-if="!rows.length" class="px-4 py-10 text-center text-sm text-slate-400">
                        {{ fm.paletteQuery ? t('fileManager.palette.noResults', { query: fm.paletteQuery }) : t('fileManager.palette.empty') }}
                    </p>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-2 text-[10px] text-slate-400 dark:border-slate-700">
                    <span class="flex items-center gap-2">
                        <kbd class="rounded border border-slate-200 px-1 dark:border-slate-700">↑↓</kbd>{{ t('fileManager.palette.hintMove') }}
                        <kbd class="rounded border border-slate-200 px-1 dark:border-slate-700">↵</kbd>{{ t('fileManager.palette.hintOpen') }}
                        <kbd class="rounded border border-slate-200 px-1 dark:border-slate-700">Ctrl ⇧ F</kbd>{{ t('fileManager.palette.modeText') }}
                    </span>
                    <span v-if="fm.paletteTruncated" class="text-amber-600 dark:text-amber-400">{{ t('fileManager.palette.truncated') }}</span>
                    <span v-else>{{ t('fileManager.palette.scope') }}</span>
                </div>
            </div>
        </div>
    </Transition>
</template>
