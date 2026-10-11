<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

// Gmail's per-message actions: a reply button and a "more" menu. Every
// message of a conversation gets the same set, so none is missing actions.
const props = defineProps({
    isDark: { type: Boolean, default: false },
    // Extra menu entries: { id, label, icon, href?, danger? }; separated from the reply group.
    items: { type: Array, default: () => [] },
});

const emit = defineEmits(['action']);

const root = ref(null);
const open = ref(false);

const ui = computed(() => (props.isDark
    ? { icon: 'text-slate-400 hover:bg-slate-800 hover:text-slate-100', menu: 'border-slate-700 bg-slate-900 text-slate-200 shadow-black/50', item: 'hover:bg-slate-800', sep: 'border-slate-800' }
    : { icon: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900', menu: 'border-slate-200 bg-white text-slate-700 shadow-slate-900/15', item: 'hover:bg-slate-100', sep: 'border-slate-100' }));

const replyItems = [
    { id: 'reply', label: 'Reply', icon: 'bi-reply' },
    { id: 'reply-all', label: 'Reply all', icon: 'bi-reply-all' },
    { id: 'forward', label: 'Forward', icon: 'bi-forward' },
];

const pick = (id) => {
    open.value = false;
    emit('action', id);
};

const onDocument = (event) => {
    if (root.value && !root.value.contains(event.target)) open.value = false;
};
const onKey = (event) => {
    if (event.key === 'Escape') open.value = false;
};
watch(open, (isOpen) => {
    const method = isOpen ? 'addEventListener' : 'removeEventListener';
    document[method]('mousedown', onDocument);
    document[method]('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocument);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div ref="root" class="relative flex shrink-0 items-center" @click.stop @keydown.enter.stop>
        <button type="button" :class="[ui.icon, 'flex h-8 w-8 items-center justify-center rounded-full transition']" title="Reply" aria-label="Reply" @click="pick('reply')">
            <i class="bi bi-reply text-base"></i>
        </button>
        <button type="button" :class="[ui.icon, 'flex h-8 w-8 items-center justify-center rounded-full transition']" title="More" aria-label="More actions" aria-haspopup="menu" :aria-expanded="open" @click="open = !open">
            <i class="bi bi-three-dots-vertical text-base"></i>
        </button>

        <transition enter-active-class="transition duration-100 ease-out" enter-from-class="scale-95 opacity-0" leave-active-class="transition duration-75" leave-to-class="opacity-0">
            <div v-if="open" :class="[ui.menu, 'absolute right-0 top-9 z-40 w-52 origin-top-right rounded-xl border py-1 text-sm shadow-xl']" role="menu">
                <button v-for="item in replyItems" :key="item.id" type="button" role="menuitem" :class="[ui.item, 'flex w-full items-center gap-3 px-3.5 py-2 text-left']" @click="pick(item.id)">
                    <i :class="['bi w-4', item.icon]"></i>{{ item.label }}
                </button>
                <template v-if="items.length">
                    <div :class="[ui.sep, 'my-1 border-t']"></div>
                    <template v-for="item in items" :key="item.id">
                        <a v-if="item.href" :href="item.href" role="menuitem" :class="[ui.item, 'flex w-full items-center gap-3 px-3.5 py-2']" @click="open = false">
                            <i :class="['bi w-4', item.icon]"></i>{{ item.label }}
                        </a>
                        <button v-else type="button" role="menuitem" :class="[ui.item, item.danger ? 'text-rose-600' : '', 'flex w-full items-center gap-3 px-3.5 py-2 text-left']" @click="pick(item.id)">
                            <i :class="['bi w-4', item.icon]"></i>{{ item.label }}
                        </button>
                    </template>
                </template>
            </div>
        </transition>
    </div>
</template>
