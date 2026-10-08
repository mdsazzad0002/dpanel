<script setup>
import { computed, ref } from 'vue';

// A plain textarea that behaves like a small code editor: line numbers, Tab
// indents two spaces, Shift+Tab outdents, Ctrl/Cmd+S asks to save. Also
// takes a dropped or chosen file.
const model = defineModel({ type: String, default: '' });

defineProps({
    rows: { type: Number, default: 18 },
    placeholder: { type: String, default: '' },
    accept: { type: String, default: '' },
    label: { type: String, default: '' },
});

const emit = defineEmits(['save']);

const area = ref(null);
const gutter = ref(null);
const fileInput = ref(null);
const lineCount = computed(() => Math.max(1, model.value.split('\n').length));

const onKey = (event) => {
    const el = event.target;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        emit('save');
        return;
    }
    if (event.key !== 'Tab') return;
    event.preventDefault();
    const { selectionStart: start, selectionEnd: end, value } = el;
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    if (event.shiftKey) {
        const removed = value.slice(lineStart, lineStart + 2).match(/^ {1,2}/)?.[0].length || 0;
        model.value = value.slice(0, lineStart) + value.slice(lineStart + removed);
        requestAnimationFrame(() => el.setSelectionRange(Math.max(lineStart, start - removed), Math.max(lineStart, end - removed)));
    } else {
        model.value = `${value.slice(0, start)}  ${value.slice(end)}`;
        requestAnimationFrame(() => el.setSelectionRange(start + 2, start + 2));
    }
};

const syncScroll = () => { if (gutter.value && area.value) gutter.value.scrollTop = area.value.scrollTop; };

const readFile = async (file) => {
    if (!file || file.size > 256 * 1024) return;
    model.value = await file.text();
};
const onDrop = (event) => readFile(event.dataTransfer?.files?.[0]);
</script>

<template>
    <div>
        <div v-if="label || accept" class="mb-1 flex items-center justify-between gap-2">
            <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ label }}</span>
            <span class="flex items-center gap-2">
                <span class="text-[11px] text-slate-400">{{ lineCount }} lines</span>
                <button v-if="accept" type="button" class="rounded-md border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="fileInput?.click()"><i class="bi bi-upload mr-1"></i>Upload file</button>
                <input v-if="accept" ref="fileInput" type="file" :accept="accept" class="hidden" @change="readFile($event.target.files?.[0]); $event.target.value = ''" />
            </span>
        </div>
        <div class="flex overflow-hidden rounded-md border border-slate-300 bg-slate-950 focus-within:ring-2 focus-within:ring-indigo-500 dark:border-slate-700" @dragover.prevent @drop.prevent="onDrop">
            <div ref="gutter" aria-hidden="true" class="select-none overflow-hidden border-r border-slate-800 bg-slate-900 px-2 py-2 text-right font-mono text-xs leading-5 text-slate-500">
                <div v-for="n in lineCount" :key="n">{{ n }}</div>
            </div>
            <textarea
                ref="area"
                v-model="model"
                :rows="rows"
                :placeholder="placeholder"
                spellcheck="false"
                autocapitalize="off"
                autocomplete="off"
                wrap="off"
                class="min-w-0 flex-1 resize-y border-0 bg-transparent px-3 py-2 font-mono text-xs leading-5 text-slate-100 placeholder:text-slate-600 focus:ring-0"
                @keydown="onKey"
                @scroll="syncScroll"
            ></textarea>
        </div>
    </div>
</template>
