<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    options: { type: Array, default: () => [] }, // [{ value, label }]
    placeholder: { type: String, default: 'Select…' },
    searchPlaceholder: { type: String, default: 'Search…' },
    disabled: { type: Boolean, default: false },
    maxVisible: { type: Number, default: 30 },
    // Remote mode: `options` is whatever the parent already fetched for the current query —
    // this component stops filtering client-side and just emits `search` for the parent to handle.
    remote: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    // Fallback label for the current modelValue when it isn't present in `options`
    // (happens in remote mode before that item's page of results has loaded).
    modelLabel: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'search']);

const root = ref(null);
const searchInput = ref(null);
const open = ref(false);
const query = ref('');
const activeIndex = ref(0);

const selected = computed(() => {
    const found = props.options.find((o) => o.value === props.modelValue);
    if (found) return found;
    if (props.modelValue !== null && props.modelValue !== '' && props.modelLabel) {
        return { value: props.modelValue, label: props.modelLabel };
    }
    return null;
});

const filtered = computed(() => {
    if (props.remote) return props.options;
    const q = query.value.trim().toLowerCase();
    if (!q) return props.options;
    return props.options.filter((o) => o.label.toLowerCase().includes(q));
});

const visible = computed(() => (props.remote ? filtered.value : filtered.value.slice(0, props.maxVisible)));

const openPanel = () => {
    if (props.disabled) return;
    open.value = true;
    activeIndex.value = 0;
    // Keep the previous keyword/results on reopen — only search again if nothing was fetched yet.
    if (props.remote && props.options.length === 0) emit('search', query.value);
    nextTick(() => searchInput.value?.focus());
};

const closePanel = () => {
    open.value = false;
};

const choose = (option) => {
    emit('update:modelValue', option.value);
    closePanel();
};

const onKeydown = (e) => {
    if (e.key === 'Escape') {
        closePanel();
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, visible.value.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const option = visible.value[activeIndex.value];
        if (option) choose(option);
    }
};

watch(query, (q) => {
    activeIndex.value = 0;
    if (props.remote) emit('search', q);
});

const onClickOutside = (e) => {
    if (open.value && root.value && !root.value.contains(e.target)) {
        closePanel();
    }
};

onMounted(() => document.addEventListener('mousedown', onClickOutside));
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside));
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :disabled="disabled"
            class="flex w-full items-center justify-between rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:bg-slate-800"
            @click="open ? closePanel() : openPanel()"
        >
            <span :class="selected ? 'text-slate-800 dark:text-slate-100' : 'text-slate-400'" class="truncate">
                {{ selected ? selected.label : placeholder }}
            </span>
            <i class="bi bi-chevron-down ml-2 shrink-0 text-xs text-slate-400"></i>
        </button>

        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <div
                v-if="open"
                class="absolute z-50 mt-1.5 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"
            >
                <div class="relative border-b border-slate-200 p-2 dark:border-slate-700">
                    <i class="bi bi-search absolute left-5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input
                        ref="searchInput"
                        v-model="query"
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="w-full rounded-lg border border-slate-300 bg-white py-1.5 pl-8 pr-7 text-sm focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500 dark:border-slate-600 dark:bg-slate-800"
                        @keydown="onKeydown"
                    />
                    <i v-if="loading" class="bi bi-arrow-repeat absolute right-5 top-1/2 -translate-y-1/2 animate-spin text-xs text-slate-400"></i>
                    <button
                        v-else-if="query"
                        type="button"
                        class="absolute right-5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                        @click="query = ''"
                    >
                        <i class="bi bi-x-circle-fill text-xs"></i>
                    </button>
                </div>

                <ul class="max-h-72 overflow-y-auto py-1 text-sm">
                    <li v-if="loading && visible.length === 0" class="px-3 py-4 text-center text-xs text-slate-400">Loading…</li>
                    <li v-else-if="visible.length === 0" class="px-3 py-4 text-center text-xs text-slate-400">No matches.</li>
                    <template v-else>
                        <li
                            v-for="(option, index) in visible"
                            :key="option.value"
                            class="cursor-pointer truncate px-3 py-2"
                            :class="option.value === modelValue
                                ? 'bg-cyan-600 text-white'
                                : index === activeIndex
                                    ? 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100'
                                    : 'text-slate-700 dark:text-slate-200'"
                            @mouseenter="activeIndex = index"
                            @click="choose(option)"
                        >
                            {{ option.label }}
                        </li>
                    </template>
                </ul>

                <div v-if="!remote && filtered.length > visible.length" class="border-t border-slate-200 px-3 py-1.5 text-center text-[11px] text-slate-400 dark:border-slate-700">
                    Showing {{ visible.length }} of {{ filtered.length }} — keep typing to narrow results
                </div>
            </div>
        </Transition>
    </div>
</template>
