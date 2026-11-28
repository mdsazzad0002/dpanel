<script setup>
// The file manager's code editor: CodeMirror 6 behind a small v-model shell.
//
// This component is loaded lazily (see FileEditor.vue), so none of CodeMirror
// ships in the file manager's own chunk — it arrives only when a file is
// actually opened for editing. The language grammar is a further chunk on
// top of that (see helpers/editorLanguages.js).
import { Compartment, EditorState } from '@codemirror/state';
import { EditorView, keymap } from '@codemirror/view';
import { indentWithTab } from '@codemirror/commands';
import { indentUnit } from '@codemirror/language';
import { search } from '@codemirror/search';
import { basicSetup } from 'codemirror';
import { oneDark } from '@codemirror/theme-one-dark';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { loadLanguage } from '../helpers/editorLanguages';

const props = defineProps({
    modelValue: { type: String, default: '' },
    fileName: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
    wrap: { type: Boolean, default: false },
    /** 1-based line a search result asked the editor to reveal; 0 for none. */
    gotoLine: { type: Number, default: 0 },
});

const emit = defineEmits(['update:modelValue', 'save', 'cursor', 'toggle-wrap', 'goto-done']);

const host = ref(null);

/** @type {EditorView | null} */
let view = null;
let themeObserver = null;

// Reconfigured in place rather than rebuilt, so switching theme or file type
// never costs the user their cursor, selection or undo history.
const language = new Compartment();
const theme = new Compartment();
const editable = new Compartment();
const wrapping = new Compartment();

const isDark = () => document.documentElement.classList.contains('dark');

/** The panel paints `dark` on <html>; follow it rather than duplicating the store. */
const themeExtension = () => (isDark() ? oneDark : []);

const wrapExtension = () => (props.wrap ? EditorView.lineWrapping : []);

const editableExtension = () => [
    EditorView.editable.of(!props.readonly),
    EditorState.readOnly.of(props.readonly),
];

function reportCursor() {
    if (!view) return;

    const { head } = view.state.selection.main;
    const line = view.state.doc.lineAt(head);

    emit('cursor', { line: line.number, column: head - line.from + 1 });
}

// Ctrl/Cmd+S belongs to the file, not to the browser's "save page"; Alt+Z is
// the word-wrap toggle, as in VS Code.
const panelKeymap = keymap.of([
    {
        key: 'Mod-s',
        preventDefault: true,
        run: () => {
            emit('save');
            return true;
        },
    },
    {
        key: 'Alt-z',
        preventDefault: true,
        run: () => {
            emit('toggle-wrap');
            return true;
        },
    },
]);

onMounted(async () => {
    view = new EditorView({
        parent: host.value,
        state: EditorState.create({
            doc: props.modelValue,
            extensions: [
                panelKeymap,
                // The find and replace panel sits at the top, where VS Code
                // puts it, instead of CodeMirror's default bottom bar.
                search({ top: true }),
                basicSetup,
                keymap.of([indentWithTab]),
                indentUnit.of('    '),
                wrapping.of(wrapExtension()),
                language.of([]),
                theme.of(themeExtension()),
                editable.of(editableExtension()),
                EditorView.theme({
                    '&': { height: '100%', fontSize: '13px' },
                    '.cm-scroller': {
                        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
                        lineHeight: '1.55',
                    },
                    '&.cm-focused': { outline: 'none' },
                }),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) {
                        emit('update:modelValue', update.state.doc.toString());
                    }
                    if (update.docChanged || update.selectionSet) {
                        reportCursor();
                    }
                }),
            ],
        }),
    });

    reportCursor();

    // A freshly switched tab builds a new editor; without this the page has
    // no focused editor, and Ctrl/Cmd+S would reach the browser instead.
    view.focus();
    revealLine(props.gotoLine);

    themeObserver = new MutationObserver(() => {
        view?.dispatch({ effects: theme.reconfigure(themeExtension()) });
    });
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });

    await applyLanguage(props.fileName);
});

onBeforeUnmount(() => {
    themeObserver?.disconnect();
    themeObserver = null;
    view?.destroy();
    view = null;
});

/** Puts the cursor on a line and scrolls it into the middle of the view. */
function revealLine(lineNumber) {
    if (!view || !lineNumber) return;

    const line = view.state.doc.line(Math.min(Math.max(1, lineNumber), view.state.doc.lines));
    view.dispatch({
        selection: { anchor: line.from },
        effects: EditorView.scrollIntoView(line.from, { y: 'center' }),
    });
    emit('goto-done');
}

watch(() => props.gotoLine, (line) => revealLine(line));

async function applyLanguage(fileName) {
    const support = await loadLanguage(fileName);

    // The grammar arrives asynchronously; by then the user may have closed
    // the editor or opened a different file.
    if (!view || fileName !== props.fileName) return;

    view.dispatch({ effects: language.reconfigure(support ?? []) });
}

watch(() => props.fileName, (fileName) => applyLanguage(fileName));

watch(() => props.wrap, () => {
    view?.dispatch({ effects: wrapping.reconfigure(wrapExtension()) });
});

watch(() => props.readonly, () => {
    view?.dispatch({ effects: editable.reconfigure(editableExtension()) });
});

// Only for changes that did not come from typing — a different file, or the
// full contents replacing a truncated preview.
watch(() => props.modelValue, (value) => {
    if (!view || value === view.state.doc.toString()) return;

    view.dispatch({
        changes: { from: 0, to: view.state.doc.length, insert: value ?? '' },
    });
});

defineExpose({
    focus: () => view?.focus(),
});
</script>

<template>
    <div ref="host" class="min-h-0 w-full flex-1 overflow-hidden text-left"></div>
</template>
