<script setup>
import { computed, ref } from 'vue';
import { useMailContacts } from '../composables/useMailContacts';
import { avatarColorOf, formatAddress, initialsOf, splitAddresses } from './mailAddresses';

// A comma-separated address field that suggests saved and recent contacts
// for the address being typed.
const model = defineModel({ type: String, default: '' });

const props = defineProps({
    id: { type: String, default: '' },
    isDark: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    inputClass: { type: [String, Array], default: '' },
});

const contacts = useMailContacts();
const input = ref(null);
const focused = ref(false);
const highlighted = ref(0);

// The address being typed is whatever follows the last comma.
const lastSeparator = computed(() => Math.max(model.value.lastIndexOf(','), model.value.lastIndexOf(';')));
const query = computed(() => model.value.slice(lastSeparator.value + 1).trim());
const suggestions = computed(() => (focused.value && contacts
    ? contacts.suggest(query.value, splitAddresses(model.value.slice(0, lastSeparator.value + 1)))
    : []));

const ui = computed(() => (props.isDark
    ? { menu: 'border-slate-700 bg-slate-900 shadow-black/50', item: 'text-slate-200', hover: 'bg-slate-800', muted: 'text-slate-500' }
    : { menu: 'border-slate-200 bg-white shadow-slate-900/15', item: 'text-slate-800', hover: 'bg-slate-100', muted: 'text-slate-500' }));

const pick = (contact) => {
    const before = model.value.slice(0, lastSeparator.value + 1).trim();
    model.value = `${before ? `${before} ` : ''}${formatAddress(contact.name, contact.email)}, `;
    highlighted.value = 0;
    input.value?.focus();
};

const onKeydown = (event) => {
    if (!suggestions.value.length) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        highlighted.value = (highlighted.value + step + suggestions.value.length) % suggestions.value.length;
    }
    else if (event.key === 'Enter' || event.key === 'Tab') {
        event.preventDefault();
        pick(suggestions.value[Math.min(highlighted.value, suggestions.value.length - 1)]);
    }
    else if (event.key === 'Escape') {
        focused.value = false;
    }
};

const onFocus = () => {
    focused.value = true;
    contacts?.load();
};

// Delay so a click on a suggestion lands before the list closes.
const onBlur = () => setTimeout(() => { focused.value = false; }, 150);

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative min-w-0 flex-1">
        <input
            :id="id"
            ref="input"
            v-model="model"
            type="text"
            autocomplete="off"
            :class="inputClass"
            :placeholder="placeholder"
            @input="highlighted = 0"
            @focus="onFocus"
            @blur="onBlur"
            @keydown="onKeydown"
        >
        <ul v-if="suggestions.length" :class="[ui.menu, 'absolute left-0 top-full z-30 mt-1 w-full min-w-[18rem] overflow-hidden rounded-xl border py-1 shadow-xl']" role="listbox">
            <li
                v-for="(contact, i) in suggestions"
                :key="contact.email"
                role="option"
                :aria-selected="i === highlighted"
                :class="[ui.item, i === highlighted ? ui.hover : '', 'flex cursor-pointer items-center gap-3 px-3 py-2']"
                @mouseenter="highlighted = i"
                @mousedown.prevent="pick(contact)"
            >
                <span :class="[avatarColorOf(contact.email), 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white']">{{ initialsOf(contact.name || contact.email) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{ contact.name || contact.email }}</span>
                    <span v-if="contact.name" :class="[ui.muted, 'block truncate text-xs']">{{ contact.email }}</span>
                </span>
                <i v-if="contact.saved" class="bi bi-person-check text-blue-500" title="Saved contact"></i>
            </li>
        </ul>
    </div>
</template>
