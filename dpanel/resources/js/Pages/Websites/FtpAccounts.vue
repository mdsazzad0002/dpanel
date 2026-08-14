<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ website: Object, accounts: Array, connection: Object });
const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);
const rows = ref([...(props.accounts || [])]);
const form = ref({ username: '', password: '', directory: '' });
const passwordAccount = ref(null);
const newPassword = ref('');
const busy = ref('');
const message = ref('');
const error = ref('');
const fullDirectoryPath = computed(() => {
    const root = String(props.website?.root_path || '').replace(/\/+$/, '');
    const relative = String(form.value.directory || '').replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
    return relative ? `${root}/${relative}` : root;
});
const report = (text, failed = false) => { message.value = failed ? '' : text; error.value = failed ? text : ''; };
const apiError = (exception, fallback) => exception?.response?.data?.message || Object.values(exception?.response?.data?.errors || {}).flat()[0] || fallback;
const generatePassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    return Array.from(crypto.getRandomValues(new Uint32Array(20)), value => chars[value % chars.length]).join('');
};
const createAccount = async () => {
    if (busy.value) return; busy.value = 'create'; report('');
    try {
        const response = await window.axios.post(panelRoute('websites.ftp.store', { id: props.website.id }), form.value);
        rows.value.unshift(response.data.account); form.value = { username: '', password: '', directory: '' }; report(response.data.message);
    } catch (exception) { report(apiError(exception, 'Unable to create FTP account.'), true); }
    finally { busy.value = ''; }
};
const changePassword = async () => {
    if (!passwordAccount.value || busy.value) return; busy.value = `password-${passwordAccount.value.id}`; report('');
    try {
        const response = await window.axios.patch(panelRoute('websites.ftp.password', { id: props.website.id, account: passwordAccount.value.id }), { password: newPassword.value });
        passwordAccount.value = null; newPassword.value = ''; report(response.data.message);
    } catch (exception) { report(apiError(exception, 'Unable to update password.'), true); }
    finally { busy.value = ''; }
};
const remove = async (account) => {
    if (busy.value || !confirm(`Delete FTP account ${account.username}? Client access will stop immediately.`)) return;
    busy.value = `delete-${account.id}`; report('');
    try {
        const response = await window.axios.delete(panelRoute('websites.ftp.destroy', { id: props.website.id, account: account.id }));
        rows.value = rows.value.filter(row => row.id !== account.id); report(response.data.message);
    } catch (exception) { report(apiError(exception, 'Unable to delete FTP account.'), true); }
    finally { busy.value = ''; }
};
</script>

<template>
    <Head :title="`FTP Accounts - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div><h1 class="text-lg font-semibold">FTP Accounts</h1><p class="text-sm text-slate-500">{{ website.domain }}</p></div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700">Back to Manage</Link>
            </div>
        </template>
        <div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
            <div v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{{ message }}</div>
            <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ error }}</div>
            <section class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-3">
                <div><p class="text-xs uppercase text-slate-500">Host</p><p class="mt-1 font-mono text-sm">{{ connection.host }}</p></div>
                <div><p class="text-xs uppercase text-slate-500">Port</p><p class="mt-1 font-mono text-sm">{{ connection.port }}</p></div>
                <div><p class="text-xs uppercase text-slate-500">Security</p><p class="mt-1 text-sm">{{ connection.tls ? 'Explicit TLS (FTPS)' : 'Plain FTP' }}</p></div>
            </section>
            <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="createAccount">
                <h2 class="font-semibold">Create client account</h2><p class="mt-1 text-sm text-slate-500">Restricted to this website directory. Passwords are never stored in dPanel.</p>
                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <label class="text-sm">Username<input v-model="form.username" required minlength="5" maxlength="32" pattern="ftp_[a-z0-9_]+" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950" placeholder="ftp_client" /></label>
                    <label class="text-sm">Password<div class="mt-1 flex"><input v-model="form.password" required minlength="12" type="text" class="w-full rounded-l-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950" /><button type="button" class="rounded-r-lg border border-l-0 px-3 text-xs dark:border-slate-700" @click="form.password = generatePassword()">Generate</button></div></label>
                    <label class="text-sm">Subdirectory (optional)<input v-model="form.directory" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950" placeholder="uploads" /></label>
                </div>
                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-950">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Full directory path</p>
                    <p class="mt-1 break-all font-mono text-sm text-slate-700 dark:text-slate-200">{{ fullDirectoryPath || 'Website root path unavailable' }}</p>
                </div>
                <button :disabled="Boolean(busy)" class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{{ busy === 'create' ? 'Creating…' : 'Create FTP account' }}</button>
            </form>
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b p-5 dark:border-slate-800"><h2 class="font-semibold">Accounts</h2></div>
                <div v-if="!rows.length" class="p-8 text-center text-sm text-slate-500">No FTP accounts yet.</div>
                <div v-for="account in rows" :key="account.id" class="flex flex-col gap-3 border-b p-5 last:border-0 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                    <div><p class="font-mono text-sm font-semibold">{{ account.username }}</p><p class="mt-1 break-all text-xs text-slate-500">{{ account.directory }}</p></div>
                    <div class="flex gap-2"><button class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700" @click="passwordAccount = account; newPassword = generatePassword()">Change password</button><button class="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-600" :disabled="Boolean(busy)" @click="remove(account)">Delete</button></div>
                </div>
            </section>
        </div>
        <div v-if="passwordAccount" class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" @click.self="passwordAccount = null">
            <form class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-slate-900" @submit.prevent="changePassword">
                <h2 class="font-semibold">Change {{ passwordAccount.username }} password</h2>
                <input v-model="newPassword" required minlength="12" type="text" class="mt-4 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
                <div class="mt-4 flex justify-end gap-2"><button type="button" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700" @click="passwordAccount = null">Cancel</button><button class="rounded-lg bg-indigo-600 px-3 py-2 text-sm text-white">Update password</button></div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
