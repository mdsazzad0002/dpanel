<script setup>
// Task S5: folders that ask for a username and password.
import InputError from '@/Components/InputError.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    website: { type: Object, required: true },
});

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const form = useForm({ path: '/', realm: '', username: '', password: '' });

const addUser = (path = null) => {
    if (path !== null) form.path = path;
    form.post(panelRoute('rules.site-protection.users.store', { website: props.website.id }), {
        preserveScroll: true,
        onSuccess: () => form.reset('username', 'password'),
    });
};

const removeUser = (user, path) => {
    if (!confirm(`Remove ${user.username} from ${path}?`)) return;
    router.delete(panelRoute('rules.site-protection.users.destroy', { user: user.id }), { preserveScroll: true });
};
</script>

<template>
    <section class="rounded-lg border border-slate-200 p-5 dark:border-slate-700">
        <h2 class="text-lg font-semibold">Password-protected folders</h2>
        <p class="mt-1 text-sm text-slate-500">Visitors to a folder (and everything under it) must sign in. Use <code>/</code> for the whole site. Certificate renewal is never blocked.</p>

        <ul v-if="website.directories.length" class="mt-4 divide-y divide-slate-200 rounded-md border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
            <li v-for="directory in website.directories" :key="directory.path" class="p-3">
                <div class="flex items-center justify-between gap-2">
                    <p class="font-mono text-sm">{{ directory.path }}</p>
                    <span v-if="directory.realm" class="text-xs text-slate-500">{{ directory.realm }}</span>
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span v-for="user in directory.users" :key="user.id" class="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800">
                        <i class="bi bi-person"></i>{{ user.username }}
                        <button type="button" class="ml-1 text-red-600 hover:text-red-700" :aria-label="`Remove ${user.username}`" @click="removeUser(user, directory.path)">
                            <i class="bi bi-x"></i>
                        </button>
                    </span>
                </div>
            </li>
        </ul>

        <form class="mt-4 grid gap-3 md:grid-cols-2" @submit.prevent="addUser()">
            <div>
                <label class="mb-1 block text-sm">Folder</label>
                <input v-model="form.path" type="text" placeholder="/admin" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" />
                <InputError :message="form.errors.path" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm">Prompt shown by the browser (optional)</label>
                <input v-model="form.realm" type="text" placeholder="Restricted" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                <InputError :message="form.errors.realm" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm">Username</label>
                <input v-model="form.username" type="text" autocomplete="off" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                <InputError :message="form.errors.username" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm">Password</label>
                <input v-model="form.password" type="password" autocomplete="new-password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                <InputError :message="form.errors.password" class="mt-1" />
            </div>
            <div class="md:col-span-2">
                <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                    Add user to folder
                </button>
                <p class="mt-1 text-xs text-slate-500">Adding an existing username again changes its password.</p>
            </div>
        </form>
    </section>
</template>
