<script setup>
import { computed, onMounted, ref, watch } from 'vue';

// Gmail-style message editor: a contenteditable body with a formatting bar.
// execCommand is deprecated but still the only built-in way to edit rich
// text, and every browser supports the commands used here.
const html = defineModel('html', { type: String, default: '' });
const text = defineModel('text', { type: String, default: '' });

const props = defineProps({
    isDark: { type: Boolean, default: false },
    placeholder: { type: String, default: 'Write your message…' },
    minHeight: { type: String, default: '12rem' },
    // The formatting bar can be hidden like Gmail's "Aa" toggle.
    showToolbar: { type: Boolean, default: true },
});

const emit = defineEmits(['submit']);

const editor = ref(null);
const openMenu = ref('');
const active = ref({});

const fonts = [
    ['Sans Serif', 'Arial, Helvetica, sans-serif'],
    ['Serif', 'Georgia, "Times New Roman", serif'],
    ['Fixed width', '"Courier New", monospace'],
    ['Wide', '"Arial Black", sans-serif'],
    ['Narrow', '"Arial Narrow", sans-serif'],
    ['Comic', '"Comic Sans MS", cursive'],
];
const sizes = [['Small', '2', '13px'], ['Normal', '3', '16px'], ['Large', '5', '24px'], ['Huge', '7', '32px']];
const colors = ['#000000', '#444444', '#666666', '#999999', '#cc0000', '#e69138', '#f1c232', '#6aa84f', '#45818e', '#3c78d8', '#3d85c6', '#674ea7', '#a61c00', '#0b5394', '#ffffff'];
const aligns = [['justifyLeft', 'bi-text-left', 'Align left'], ['justifyCenter', 'bi-text-center', 'Center'], ['justifyRight', 'bi-text-right', 'Align right']];
const fontLabel = ref('Sans Serif');

const ui = computed(() => (props.isDark
    ? {
        bar: 'bg-slate-800/80 text-slate-300',
        button: 'hover:bg-slate-700 hover:text-white',
        on: 'bg-slate-700 text-white',
        menu: 'border-slate-700 bg-slate-900 text-slate-200 shadow-black/40',
        menuItem: 'hover:bg-slate-800',
        sep: 'bg-slate-700',
        body: 'text-slate-100',
        placeholder: 'before:text-slate-500',
        quote: '[&_blockquote]:border-slate-600 [&_blockquote]:text-slate-400',
    }
    : {
        bar: 'bg-slate-100 text-slate-700',
        button: 'hover:bg-slate-200 hover:text-slate-900',
        on: 'bg-slate-300/70 text-slate-900',
        menu: 'border-slate-200 bg-white text-slate-800 shadow-slate-900/15',
        menuItem: 'hover:bg-slate-100',
        sep: 'bg-slate-300',
        body: 'text-slate-900',
        placeholder: 'before:text-slate-400',
        quote: '[&_blockquote]:border-slate-300 [&_blockquote]:text-slate-600',
    }));

const empty = computed(() => text.value.trim() === '' && !/<(img|hr|li)\b/i.test(html.value));

const sync = () => {
    if (!editor.value) return;
    html.value = editor.value.innerHTML;
    text.value = editor.value.innerText.replace(/ /g, ' ');
};

const refreshState = () => {
    const state = {};
    for (const command of ['bold', 'italic', 'underline', 'strikeThrough', 'insertOrderedList', 'insertUnorderedList', 'justifyCenter', 'justifyRight']) {
        try { state[command] = document.queryCommandState(command); }
        catch { state[command] = false; }
    }
    active.value = state;
};

const run = (command, value = null) => {
    editor.value?.focus();
    document.execCommand('styleWithCSS', false, command === 'foreColor' || command === 'fontName');
    document.execCommand(command, false, value);
    openMenu.value = '';
    sync();
    refreshState();
};

const setFont = ([label, family]) => {
    fontLabel.value = label;
    run('fontName', family);
};

const quote = () => {
    const inQuote = document.getSelection()?.anchorNode?.parentElement?.closest('blockquote');
    run('formatBlock', inQuote ? 'div' : 'blockquote');
};

const toggleMenu = (name) => {
    openMenu.value = openMenu.value === name ? '' : name;
};

const onKeydown = (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        // The compose window also listens for this; send only once.
        event.stopPropagation();
        emit('submit');
    }
};

// Pasted rich text keeps its markup; the server sanitizes what is sent.
const onPaste = () => setTimeout(sync, 0);

// Set from outside (reply quote, forward, cleared draft) without moving the caret on each keystroke.
watch(html, (value) => {
    if (editor.value && value !== editor.value.innerHTML) {
        editor.value.innerHTML = value;
        text.value = editor.value.innerText;
    }
});

onMounted(() => {
    if (editor.value) editor.value.innerHTML = html.value;
});

const focus = (atStart = true) => {
    const el = editor.value;
    if (!el) return;
    el.focus();
    const range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(atStart);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
};

defineExpose({ focus });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div
            ref="editor"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            :aria-label="placeholder"
            :data-placeholder="placeholder"
            :class="[
                ui.body, ui.quote,
                'relative min-h-0 flex-1 overflow-y-auto break-words px-4 py-3 text-[15px] leading-7 outline-none',
                '[&_blockquote]:my-2 [&_blockquote]:border-l-4 [&_blockquote]:pl-3 [&_ol]:list-decimal [&_ol]:pl-6 [&_ul]:list-disc [&_ul]:pl-6 [&_a]:text-blue-600 [&_a]:underline',
                empty ? ['before:pointer-events-none before:absolute before:content-[attr(data-placeholder)]', ui.placeholder] : '',
            ]"
            :style="{ minHeight }"
            @input="sync"
            @keyup="refreshState"
            @mouseup="refreshState"
            @keydown="onKeydown"
            @paste="onPaste"
        ></div>

        <slot />

        <div v-if="showToolbar" class="shrink-0 px-3 pb-2">
            <div :class="[ui.bar, 'relative flex flex-wrap items-center gap-0.5 rounded-full px-2 py-1 text-sm']" role="toolbar" aria-label="Formatting" @mousedown.prevent>
                <button type="button" :class="[ui.button, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Undo (Ctrl+Z)" @click="run('undo')"><i class="bi bi-arrow-counterclockwise"></i></button>
                <button type="button" :class="[ui.button, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Redo (Ctrl+Y)" @click="run('redo')"><i class="bi bi-arrow-clockwise"></i></button>
                <span :class="[ui.sep, 'mx-1 h-5 w-px']"></span>

                <div class="relative">
                    <button type="button" :class="[ui.button, 'flex h-8 items-center gap-1.5 rounded-full px-2.5 text-[13px]']" title="Font" @click="toggleMenu('font')">
                        {{ fontLabel }} <i class="bi bi-caret-down-fill text-[9px]"></i>
                    </button>
                    <div v-if="openMenu === 'font'" :class="[ui.menu, 'absolute bottom-10 left-0 z-20 w-44 rounded-lg border py-1 shadow-xl']">
                        <button v-for="font in fonts" :key="font[0]" type="button" :class="[ui.menuItem, 'block w-full px-3 py-1.5 text-left text-sm']" :style="{ fontFamily: font[1] }" @click="setFont(font)">{{ font[0] }}</button>
                    </div>
                </div>

                <div class="relative">
                    <button type="button" :class="[ui.button, 'flex h-8 items-center gap-0.5 rounded-full px-2']" title="Size" @click="toggleMenu('size')">
                        <i class="bi bi-type"></i><i class="bi bi-caret-down-fill text-[9px]"></i>
                    </button>
                    <div v-if="openMenu === 'size'" :class="[ui.menu, 'absolute bottom-10 left-0 z-20 w-32 rounded-lg border py-1 shadow-xl']">
                        <button v-for="size in sizes" :key="size[1]" type="button" :class="[ui.menuItem, 'block w-full px-3 py-1 text-left']" @click="run('fontSize', size[1])">
                            <span :style="{ fontSize: size[2] }">{{ size[0] }}</span>
                        </button>
                    </div>
                </div>
                <span :class="[ui.sep, 'mx-1 h-5 w-px']"></span>

                <button type="button" :class="[active.bold ? ui.on : ui.button, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Bold (Ctrl+B)" @click="run('bold')"><i class="bi bi-type-bold text-base"></i></button>
                <button type="button" :class="[active.italic ? ui.on : ui.button, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Italic (Ctrl+I)" @click="run('italic')"><i class="bi bi-type-italic text-base"></i></button>
                <button type="button" :class="[active.underline ? ui.on : ui.button, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Underline (Ctrl+U)" @click="run('underline')"><i class="bi bi-type-underline text-base"></i></button>

                <div class="relative">
                    <button type="button" :class="[ui.button, 'flex h-8 items-center gap-0.5 rounded-full px-2']" title="Text colour" @click="toggleMenu('color')">
                        <i class="bi bi-fonts"></i><i class="bi bi-caret-down-fill text-[9px]"></i>
                    </button>
                    <div v-if="openMenu === 'color'" :class="[ui.menu, 'absolute bottom-10 left-0 z-20 grid w-44 grid-cols-5 gap-1.5 rounded-lg border p-2.5 shadow-xl']">
                        <button v-for="color in colors" :key="color" type="button" class="h-6 w-6 rounded-full border border-black/10 transition hover:scale-110" :style="{ background: color }" :title="color" @click="run('foreColor', color)"></button>
                    </div>
                </div>
                <span :class="[ui.sep, 'mx-1 h-5 w-px']"></span>

                <div class="relative">
                    <button type="button" :class="[ui.button, 'flex h-8 items-center gap-0.5 rounded-full px-2']" title="Align" @click="toggleMenu('align')">
                        <i :class="['bi', active.justifyCenter ? 'bi-text-center' : active.justifyRight ? 'bi-text-right' : 'bi-text-left']"></i><i class="bi bi-caret-down-fill text-[9px]"></i>
                    </button>
                    <div v-if="openMenu === 'align'" :class="[ui.menu, 'absolute bottom-10 left-0 z-20 flex gap-0.5 rounded-lg border p-1 shadow-xl']">
                        <button v-for="align in aligns" :key="align[0]" type="button" :class="[ui.menuItem, 'flex h-8 w-8 items-center justify-center rounded-md']" :title="align[2]" @click="run(align[0])"><i :class="['bi', align[1]]"></i></button>
                    </div>
                </div>
                <button type="button" :class="[active.insertOrderedList ? ui.on : ui.button, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Numbered list" @click="run('insertOrderedList')"><i class="bi bi-list-ol"></i></button>
                <button type="button" :class="[active.insertUnorderedList ? ui.on : ui.button, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Bulleted list" @click="run('insertUnorderedList')"><i class="bi bi-list-ul"></i></button>
                <button type="button" :class="[ui.button, 'flex h-8 w-8 items-center justify-center rounded-full max-sm:hidden']" title="Indent less" @click="run('outdent')"><i class="bi bi-text-indent-right"></i></button>
                <button type="button" :class="[ui.button, 'flex h-8 w-8 items-center justify-center rounded-full max-sm:hidden']" title="Indent more" @click="run('indent')"><i class="bi bi-text-indent-left"></i></button>
                <button type="button" :class="[ui.button, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Quote" @click="quote"><i class="bi bi-quote"></i></button>
                <span :class="[ui.sep, 'mx-1 h-5 w-px max-sm:hidden']"></span>
                <button type="button" :class="[active.strikeThrough ? ui.on : ui.button, 'flex h-8 w-8 items-center justify-center rounded-full max-sm:hidden']" title="Strikethrough" @click="run('strikeThrough')"><i class="bi bi-type-strikethrough"></i></button>
                <button type="button" :class="[ui.button, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Remove formatting" @click="run('removeFormat')"><i class="bi bi-eraser"></i></button>
            </div>
        </div>
    </div>
</template>
