<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { useMailContacts } from '../composables/useMailContacts';
import MailAvatar from './MailAvatar.vue';
import { avatarColorOf, displayName, emailOf, formatAddress, initialsOf } from './mailAddresses';

// Gmail-style hover card around a sender or recipient: who they are, save
// them as a contact, or start a message to them.
const props = defineProps({
    address: { type: String, default: '' },
    isDark: { type: Boolean, default: false },
    avatarEndpoint: { type: String, default: '' },
    verified: { type: Boolean, default: false },
});

const emit = defineEmits(['compose']);

const contacts = useMailContacts();
const open = ref(false);
const saving = ref(false);
const copied = ref(false);
const failed = ref('');
let timer = null;

const email = computed(() => emailOf(props.address).toLowerCase());
const contact = computed(() => contacts?.find(email.value) || null);
const name = computed(() => contact.value?.name || displayName(props.address) || email.value.split('@')[0]);
const saved = computed(() => Boolean(contact.value?.saved));

const ui = computed(() => (props.isDark
    ? { card: 'border-slate-700 bg-slate-900 shadow-black/50', name: 'text-slate-100', muted: 'text-slate-400', icon: 'text-slate-300 hover:bg-slate-800', ghost: 'border-slate-700 text-slate-300 hover:bg-slate-800', primary: 'bg-blue-500/20 text-blue-200 hover:bg-blue-500/30' }
    : { card: 'border-slate-200 bg-white shadow-slate-900/15', name: 'text-slate-900', muted: 'text-slate-500', icon: 'text-slate-600 hover:bg-slate-100', ghost: 'border-slate-300 text-slate-600 hover:bg-slate-100', primary: 'bg-blue-100 text-blue-900 hover:bg-blue-200' }));

const show = () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        open.value = true;
        contacts?.load();
    }, 350);
};
const hide = () => {
    clearTimeout(timer);
    timer = setTimeout(() => { open.value = false; }, 200);
};

const toggleSaved = async () => {
    if (!contacts || saving.value) return;
    saving.value = true;
    failed.value = '';
    try {
        if (saved.value) await contacts.remove(contact.value);
        else await contacts.save(email.value, name.value);
    }
    catch (error) {
        failed.value = error?.response?.data?.message || error?.message || 'Could not update contacts.';
    }
    finally {
        saving.value = false;
    }
};

const copy = async () => {
    try {
        await navigator.clipboard.writeText(email.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1200);
    }
    catch { /* clipboard blocked */ }
};

const compose = () => {
    open.value = false;
    emit('compose', formatAddress(name.value, email.value));
};

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <span class="relative inline-flex min-w-0 max-w-full" @mouseenter="show" @mouseleave="hide" @focusin="show" @focusout="hide">
        <slot />
        <transition enter-active-class="transition duration-150 ease-out" enter-from-class="translate-y-1 opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
            <div
                v-if="open && email"
                :class="[ui.card, 'absolute left-0 top-full z-40 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] cursor-default rounded-2xl border p-5 text-left shadow-2xl']"
                role="dialog"
                :aria-label="`Contact ${name}`"
                @mouseenter="show"
                @mouseleave="hide"
                @click.stop
            >
                <div class="flex items-start gap-4">
                    <MailAvatar
                        :email="email"
                        :initials="initialsOf(name)"
                        :color-class="avatarColorOf(email)"
                        :verified="verified"
                        :endpoint="avatarEndpoint"
                        class="h-16 w-16 shrink-0 text-xl"
                    />
                    <div class="min-w-0 flex-1 pt-1">
                        <p :class="[ui.name, 'truncate text-lg font-medium']">{{ name }}</p>
                        <p :class="[ui.muted, 'truncate text-sm']">{{ email }}</p>
                        <p v-if="saved" class="mt-1 text-xs font-medium text-blue-600 dark:text-blue-400"><i class="bi bi-person-check-fill mr-1"></i>In your contacts</p>
                    </div>

                </div>
                <p v-if="failed" class="mt-3 text-xs text-rose-600">{{ failed }}</p>
                <div class="mt-4 flex items-center gap-2">
                    <button type="button" :class="[ui.primary, 'inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-full text-sm font-medium transition']" @click="compose">
                        <i class="bi bi-envelope"></i> Send mail
                    </button>
                    <button
                        type="button"
                        :class="[saved ? 'border-emerald-300 text-emerald-700 hover:border-rose-300 hover:text-rose-600 dark:border-emerald-800 dark:text-emerald-300' : ui.ghost, 'group/save inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-full border text-sm font-medium transition disabled:opacity-60']"
                        :title="saved ? 'Remove from contacts' : 'Save to contacts'"
                        :disabled="saving"
                        @click="toggleSaved"
                    >
                        <i :class="['bi', saving ? 'bi-arrow-repeat animate-spin' : saved ? 'bi-person-check-fill group-hover/save:hidden' : 'bi-person-plus']"></i>
                        <i v-if="saved && !saving" class="bi bi-person-dash hidden group-hover/save:inline"></i>
                        <span v-if="saved" class="group-hover/save:hidden">Saved</span>
                        <span v-if="saved" class="hidden group-hover/save:inline">Remove</span>
                        <span v-else>Save contact</span>
                    </button>
                    <button type="button" :class="[ui.ghost, 'flex h-10 w-10 items-center justify-center rounded-full border transition']" :title="copied ? 'Copied' : 'Copy email address'" @click="copy">
                        <i :class="['bi', copied ? 'bi-check2' : 'bi-copy']"></i>
                    </button>
                </div>
            </div>
        </transition>
    </span>
</template>
