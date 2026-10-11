<script setup>
import { computed, onMounted, ref } from 'vue';
import { useMailContacts } from '../composables/useMailContacts';
import MailAvatar from './MailAvatar.vue';
import { avatarColorOf, formatAddress, initialsOf } from './mailAddresses';

// The mailbox address book page: saved contacts, plus people you have written to.
const props = defineProps({
    isDark: { type: Boolean, default: false },
    avatarEndpoint: { type: String, default: '' },
});

const emit = defineEmits(['compose', 'notify']);

const contacts = useMailContacts();
const tab = ref('saved');
const search = ref('');
const adding = ref(false);
const newName = ref('');
const newEmail = ref('');
const busy = ref('');
const editing = ref(null);
const editName = ref('');
const searchInput = ref(null);

onMounted(() => {
    contacts?.load(true);
    searchInput.value?.focus();
});

const ui = computed(() => (props.isDark
    ? { page: 'text-slate-200', muted: 'text-slate-400', strong: 'text-slate-100', divider: 'border-slate-800', row: 'hover:bg-slate-800/60', input: 'border-slate-700 bg-slate-950 text-slate-100 placeholder:text-slate-500 focus:border-blue-500', tab: 'text-slate-400 hover:text-slate-100', tabOn: 'border-blue-400 text-blue-300', icon: 'text-slate-400 hover:bg-slate-700 hover:text-slate-100' }
    : { page: 'text-slate-800', muted: 'text-slate-500', strong: 'text-slate-900', divider: 'border-slate-100', row: 'hover:bg-slate-50', input: 'border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus:border-blue-500', tab: 'text-slate-500 hover:text-slate-900', tabOn: 'border-blue-600 text-blue-700', icon: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900' }));

const all = computed(() => contacts?.contacts.value || []);
const savedCount = computed(() => all.value.filter((c) => c.saved).length);
const otherCount = computed(() => all.value.length - savedCount.value);
const list = computed(() => {
    const q = search.value.trim().toLowerCase();

    return all.value
        .filter((c) => (tab.value === 'saved' ? c.saved : !c.saved))
        .filter((c) => q === '' || c.email.includes(q) || c.name.toLowerCase().includes(q))
        .sort((a, b) => (a.name || a.email).localeCompare(b.name || b.email));
});

const act = async (key, work, success) => {
    busy.value = key;
    try {
        await work();
        if (success) emit('notify', success, 'success');
    }
    catch (error) {
        emit('notify', error?.response?.data?.message || error?.message || 'Contact could not be updated.');
    }
    finally {
        busy.value = '';
    }
};

const addContact = () => act('add', async () => {
    await contacts.save(newEmail.value.trim(), newName.value.trim());
    newEmail.value = '';
    newName.value = '';
    adding.value = false;
    tab.value = 'saved';
}, 'Contact saved.');

const startEdit = (contact) => {
    editing.value = contact.email;
    editName.value = contact.name;
};
const saveEdit = (contact) => act(contact.email, async () => {
    await contacts.save(contact.email, editName.value.trim());
    editing.value = null;
});

const keep = (contact) => act(contact.email, () => contacts.save(contact.email, contact.name), 'Added to contacts.');
const remove = (contact) => {
    if (contact.saved && !confirm(`Remove ${contact.name || contact.email} from contacts?`)) return;
    act(contact.email, () => contacts.remove(contact));
};

const compose = (contact) => emit('compose', formatAddress(contact.name, contact.email));
</script>

<template>
    <div class="h-full overflow-y-auto">
            <section :class="[ui.page, 'mx-auto flex w-full max-w-3xl flex-col py-2']" aria-label="Contacts">
                <header class="flex items-center gap-3 px-5 pb-3 pt-5">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-600/10 text-blue-600"><i class="bi bi-person-lines-fill text-lg"></i></span>
                    <div class="min-w-0 flex-1">
                        <h2 :class="[ui.strong, 'text-lg font-semibold']">Contacts</h2>
                        <p :class="[ui.muted, 'text-xs']">Saved people appear first when you type a recipient.</p>
                    </div>
                    <button type="button" class="inline-flex h-9 items-center gap-1.5 rounded-full bg-blue-600 px-4 text-sm font-medium text-white transition hover:bg-blue-700" @click="adding = !adding">
                        <i class="bi bi-person-plus"></i> New
                    </button>
                </header>

                <form v-if="adding" :class="[ui.divider, 'mx-5 mb-3 grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_1fr_auto]']" @submit.prevent="addContact">
                    <input v-model="newName" type="text" placeholder="Name" :class="[ui.input, 'h-10 rounded-lg border px-3 text-sm outline-none focus:ring-0']">
                    <input v-model="newEmail" type="email" required placeholder="email@example.com" :class="[ui.input, 'h-10 rounded-lg border px-3 text-sm outline-none focus:ring-0']">
                    <button type="submit" class="h-10 rounded-lg bg-blue-600 px-4 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" :disabled="busy === 'add'">Save</button>
                </form>

                <div class="px-5">
                    <div class="relative">
                        <i :class="[ui.muted, 'bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm']"></i>
                        <input ref="searchInput" v-model="search" type="search" placeholder="Search name or email" :class="[ui.input, 'h-10 w-full rounded-full border pl-9 pr-4 text-sm outline-none focus:ring-0']">
                    </div>
                    <div :class="[ui.divider, 'mt-3 flex gap-5 border-b text-sm']" role="tablist">
                        <button type="button" role="tab" :aria-selected="tab === 'saved'" :class="[tab === 'saved' ? ui.tabOn : ['border-transparent', ui.tab], '-mb-px border-b-2 pb-2 font-medium']" @click="tab = 'saved'">Saved ({{ savedCount }})</button>
                        <button type="button" role="tab" :aria-selected="tab === 'other'" :class="[tab === 'other' ? ui.tabOn : ['border-transparent', ui.tab], '-mb-px border-b-2 pb-2 font-medium']" @click="tab = 'other'">Other contacts ({{ otherCount }})</button>
                    </div>
                </div>

                <ul class="px-2 py-2">
                    <li v-for="contact in list" :key="contact.email" :class="[ui.row, 'group flex items-center gap-3 rounded-xl px-3 py-2.5']">
                        <MailAvatar :email="contact.email" :initials="initialsOf(contact.name || contact.email)" :color-class="avatarColorOf(contact.email)" :endpoint="avatarEndpoint" class="h-10 w-10 shrink-0 text-sm" />
                        <div class="min-w-0 flex-1">
                            <form v-if="editing === contact.email" class="flex gap-2" @submit.prevent="saveEdit(contact)">
                                <input v-model="editName" type="text" placeholder="Name" :class="[ui.input, 'h-8 min-w-0 flex-1 rounded-md border px-2 text-sm outline-none focus:ring-0']" @keydown.esc.stop="editing = null">
                                <button type="submit" class="rounded-md bg-blue-600 px-3 text-xs font-medium text-white">Save</button>
                            </form>
                            <template v-else>
                                <p :class="[ui.strong, 'truncate text-sm font-medium']">{{ contact.name || contact.email }}</p>
                                <p v-if="contact.name" :class="[ui.muted, 'truncate text-xs']">{{ contact.email }}</p>
                            </template>
                        </div>
                        <div class="flex shrink-0 items-center gap-0.5 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                            <button type="button" :class="[ui.icon, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Send mail" @click="compose(contact)"><i class="bi bi-envelope"></i></button>
                            <button v-if="contact.saved" type="button" :class="[ui.icon, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Edit name" @click="startEdit(contact)"><i class="bi bi-pencil"></i></button>
                            <button v-else type="button" :class="[ui.icon, 'flex h-8 w-8 items-center justify-center rounded-full']" title="Add to contacts" :disabled="busy === contact.email" @click="keep(contact)"><i class="bi bi-person-plus"></i></button>
                            <button type="button" :class="[ui.icon, 'flex h-8 w-8 items-center justify-center rounded-full hover:!text-rose-600']" :title="contact.saved ? 'Remove' : 'Forget'" :disabled="busy === contact.email" @click="remove(contact)"><i class="bi bi-trash3"></i></button>
                        </div>
                    </li>
                    <li v-if="!list.length" :class="[ui.muted, 'px-3 py-12 text-center text-sm']">
                        <i class="bi bi-people mb-2 block text-3xl opacity-60"></i>
                        <template v-if="search">No contacts match "{{ search }}".</template>
                        <template v-else-if="tab === 'saved'">No saved contacts yet. Hover a sender and click <i class="bi bi-person-plus"></i>, or add one with New.</template>
                        <template v-else>People you write to will appear here.</template>
                    </li>
                </ul>
            </section>
    </div>
</template>
