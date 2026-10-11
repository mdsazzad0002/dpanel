import { computed, inject, provide, ref } from 'vue';
import { emailOf } from '../components/mailAddresses';

const KEY = Symbol('mailContacts');

/**
 * The mailbox address book, provided once by the client page and injected by
 * the reader, reply box and compose window. Saved contacts are the ones the
 * user added; the rest are remembered from sent mail for suggestions.
 */
export function provideMailContacts(endpoint) {
    const contacts = ref([]);
    let loaded = null;

    const request = async (method, url, data) => {
        const response = await window.axios({ method, url, data, headers: { Accept: 'application/json' } });
        if (!response?.data?.success) throw new Error(response?.data?.message || 'Contacts request failed.');

        return response.data;
    };

    const load = (force = false) => {
        if (!endpoint) return Promise.resolve();
        if (!loaded || force) {
            loaded = request('get', endpoint)
                .then((data) => { contacts.value = data.contacts || []; })
                .catch(() => { loaded = null; });
        }

        return loaded;
    };

    const find = (address) => {
        const email = emailOf(address).toLowerCase();

        return contacts.value.find((contact) => contact.email === email) || null;
    };

    const save = async (email, name = '') => {
        const data = await request('post', endpoint, { email: emailOf(email).toLowerCase(), name });
        contacts.value = [data.contact, ...contacts.value.filter((contact) => contact.email !== data.contact.email)];

        return data.contact;
    };

    const remove = async (contact) => {
        await request('delete', `${endpoint}/${contact.id}`);
        contacts.value = contacts.value.filter((item) => item.id !== contact.id);
    };

    // Saved contacts first, then by how well the name or address matches.
    const suggest = (query, exclude = [], limit = 6) => {
        const q = String(query || '').trim().toLowerCase();
        if (q === '') return [];
        const skip = new Set(exclude.map((item) => emailOf(item).toLowerCase()));

        return contacts.value
            .filter((contact) => !skip.has(contact.email))
            .map((contact) => {
                const name = contact.name.toLowerCase();
                const rank = contact.email.startsWith(q) || name.startsWith(q) || name.includes(` ${q}`) ? 0
                    : contact.email.includes(q) || name.includes(q) ? 1 : -1;

                return { contact, rank };
            })
            .filter((item) => item.rank >= 0)
            .sort((a, b) => a.rank - b.rank || Number(b.contact.saved) - Number(a.contact.saved))
            .slice(0, limit)
            .map((item) => item.contact);
    };

    const store = { contacts, saved: computed(() => contacts.value.filter((c) => c.saved)), load, find, save, remove, suggest };
    provide(KEY, store);

    return store;
}

export function useMailContacts() {
    return inject(KEY, null);
}
