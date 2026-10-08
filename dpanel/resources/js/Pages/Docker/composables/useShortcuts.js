import { onBeforeUnmount, onMounted } from 'vue';

/** Typing in a field must never trigger a shortcut. */
const typing = (event) => {
    const el = event.target;
    return el instanceof HTMLElement && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
};

/**
 * Single-key shortcuts plus two-key "g then x" jumps, like GitHub's. `keys`
 * maps a key ('r', '/', '?', 'g c') to a handler; modifier combos are left
 * to the browser.
 */
export function useShortcuts(keys) {
    let pendingG = false;
    let timer = null;

    const onKey = (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey || typing(event)) return;
        // An open dialog owns the keyboard, apart from its own Escape.
        if (document.querySelector('dialog[open]')) return;
        const key = event.key;
        if (pendingG) {
            pendingG = false;
            window.clearTimeout(timer);
            const handler = keys[`g ${key}`];
            if (handler) {
                event.preventDefault();
                handler(event);
            }
            return;
        }
        if (key === 'g' && Object.keys(keys).some((k) => k.startsWith('g '))) {
            pendingG = true;
            timer = window.setTimeout(() => { pendingG = false; }, 1200);
            return;
        }
        const handler = keys[key];
        if (handler) {
            event.preventDefault();
            handler(event);
        }
    };

    onMounted(() => window.addEventListener('keydown', onKey));
    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onKey);
        window.clearTimeout(timer);
    });
}
