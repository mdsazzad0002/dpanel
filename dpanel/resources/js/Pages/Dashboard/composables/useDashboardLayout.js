import { computed, reactive, watch } from 'vue';

// Order, collapsed and hidden state of the dashboard's tool sections, kept in
// localStorage per user. Unknown ids (a section that no longer exists) are
// dropped on read; sections added later simply append to the end.
export function useDashboardLayout(sections, storageKey) {
    const read = () => {
        try {
            const saved = JSON.parse(window.localStorage.getItem(storageKey) ?? 'null');
            return {
                order: Array.isArray(saved?.order) ? saved.order : [],
                collapsed: Array.isArray(saved?.collapsed) ? saved.collapsed : [],
                hidden: Array.isArray(saved?.hidden) ? saved.hidden : [],
            };
        } catch {
            return { order: [], collapsed: [], hidden: [] };
        }
    };

    const state = reactive(read());

    watch(state, () => {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(state));
        } catch {
            // Private mode / blocked storage: the layout still works, it just isn't remembered.
        }
    }, { deep: true });

    const ids = computed(() => sections.value.map((section) => section.id));

    const fullOrder = () => {
        const known = state.order.filter((id) => ids.value.includes(id));
        return [...known, ...ids.value.filter((id) => !known.includes(id))];
    };

    const ordered = computed(() => {
        const byId = new Map(sections.value.map((section) => [section.id, section]));
        return fullOrder().map((id) => byId.get(id));
    });

    const visible = computed(() => ordered.value.filter((section) => !state.hidden.includes(section.id)));

    const moveTo = (id, targetId) => {
        if (id === targetId) return;
        const order = fullOrder();
        const from = order.indexOf(id);
        const to = order.indexOf(targetId);
        if (from < 0 || to < 0) return;
        order.splice(from, 1);
        order.splice(to, 0, id);
        state.order = order;
    };

    const moveBy = (id, delta) => {
        const list = visible.value.map((section) => section.id);
        const neighbour = list[list.indexOf(id) + delta];
        if (neighbour) moveTo(id, neighbour);
    };

    const toggle = (list, id, on) => {
        const has = state[list].includes(id);
        if (on && !has) state[list].push(id);
        if (!on && has) state[list] = state[list].filter((x) => x !== id);
    };

    return {
        ordered,
        visible,
        isCollapsed: (id) => state.collapsed.includes(id),
        isHidden: (id) => state.hidden.includes(id),
        allCollapsed: computed(() => visible.value.length > 0 && visible.value.every((s) => state.collapsed.includes(s.id))),
        toggleCollapsed: (id) => toggle('collapsed', id, !state.collapsed.includes(id)),
        setHidden: (id, hidden) => toggle('hidden', id, hidden),
        setAllCollapsed: (collapsed) => { state.collapsed = collapsed ? [...ids.value] : []; },
        moveTo,
        moveBy,
        reset: () => { state.order = []; state.collapsed = []; state.hidden = []; },
    };
}
