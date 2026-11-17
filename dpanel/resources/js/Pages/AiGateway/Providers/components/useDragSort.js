import { ref } from 'vue';

/**
 * Native HTML5 drag-and-drop for a list. `items` is a ref to an array; on a
 * drop it is reordered in place and `onSorted(list)` gets the new order.
 * A row only becomes draggable while its handle is held, so inputs, buttons
 * and text in the row keep working, and nested lists don't drag the parent.
 */
export function useDragSort(items, onSorted) {
    const armed = ref(null);
    const from = ref(null);
    const over = ref(null);

    const reset = () => { armed.value = null; from.value = null; over.value = null; };

    const handle = (i) => ({
        onMousedown: () => { armed.value = i; },
        onTouchstart: () => { armed.value = i; },
        onMouseup: () => { if (from.value === null) armed.value = null; },
    });

    const row = (i) => ({
        draggable: armed.value === i,
        onDragstart: (e) => {
            if (armed.value !== i) return;
            e.stopPropagation();
            from.value = i;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(i));
        },
        onDragover: (e) => {
            if (from.value === null) return;
            e.preventDefault();
            e.stopPropagation();
            over.value = i;
        },
        onDrop: (e) => {
            if (from.value === null) return;
            e.preventDefault();
            e.stopPropagation();
            const start = from.value;
            reset();
            if (start === i) return;
            const list = [...items.value];
            const [moved] = list.splice(start, 1);
            list.splice(i, 0, moved);
            items.value = list;
            onSorted(list);
        },
        onDragend: reset,
    });

    // Where the dragged row would land: a line above or below this row.
    const dropClass = (i) => {
        if (from.value === null || over.value !== i || from.value === i) return '';
        return from.value < i ? 'drop-below' : 'drop-above';
    };
    const isDragging = (i) => from.value === i;

    return { handle, row, dropClass, isDragging };
}
