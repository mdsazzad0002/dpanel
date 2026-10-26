<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import FtpTabs from '@/Pages/Ftp/components/FtpTabs.vue';
import { generatePassword, securityLabel } from '@/Pages/Ftp/ftp';

const props = defineProps({
    websites: { type: Array, default: () => [] },
    connection: { type: Object, default: () => ({}) },
    selectedWebsiteId: { type: String, default: '' },
});
const page = usePage();
const { panelRoute } = usePanelApi();

const websiteById = computed(() => Object.fromEntries(props.websites.map((site) => [String(site.id), site])));
const form = useForm({
    website_id: websiteById.value[props.selectedWebsiteId] ? props.selectedWebsiteId : '',
    username: '',
    password: generatePassword(),
    directory: '',
});
const fullDirectoryPath = computed(() => {
    const root = String(websiteById.value[form.website_id]?.root_path || '').replace(/\/+$/, '');
    if (!root) return '';
    const relative = String(form.directory || '').replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
    return relative ? `${root}/${relative}` : root;
});
const submit = () => form.post(panelRoute('ftp.store'), { preserveScroll: true });
</script>

<template>
    <Head title="Create FTP Account" />
    <AuthenticatedLayout>
        <template #header>
            <div><h1 class="text-lg font-semibold">Create FTP Account</h1><p class="text-sm text-slate-500 dark:text-slate-400">Give a client FTP access to one website's directory.</p></div>
        </template>
        <div class="space-y-5">
            <FtpTabs active="create" />
            <div v-if="page.props.flash?.error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">{{ page.props.flash.error }}</div>
            <div class="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                <form class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                    <h2 class="font-semibold">Account details</h2>
                    <p class="mt-1 text-sm text-slate-500">The account is locked to the chosen directory. Passwords are never stored in dPanel, so copy it before you save.</p>
                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <label class="text-sm">Website
                            <select v-model="form.website_id" required class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950">
                                <option value="" disabled>Select a website</option>
                                <option v-for="site in websites" :key="site.id" :value="site.id">{{ site.domain }}</option>
                            </select>
                            <span v-if="form.errors.website_id" class="mt-1 block text-xs text-red-600">{{ form.errors.website_id }}</span>
                        </label>
                        <label class="text-sm">Username
                            <input v-model="form.username" required minlength="5" maxlength="32" pattern="ftp_[a-z0-9_]+" class="mt-1 w-full rounded-lg border-slate-300 font-mono dark:border-slate-700 dark:bg-slate-950" placeholder="ftp_client" />
                            <span class="mt-1 block text-xs text-slate-500">Starts with <code>ftp_</code>; lowercase letters, numbers and underscores.</span>
                            <span v-if="form.errors.username" class="mt-1 block text-xs text-red-600">{{ form.errors.username }}</span>
                        </label>
                        <label class="text-sm">Password
                            <div class="mt-1 flex"><input v-model="form.password" required minlength="12" type="text" class="w-full min-w-0 rounded-l-lg border-slate-300 font-mono dark:border-slate-700 dark:bg-slate-950" /><button type="button" class="rounded-r-lg border border-l-0 border-slate-300 px-3 text-xs dark:border-slate-700" @click="form.password = generatePassword()">Generate</button></div>
                            <span v-if="form.errors.password" class="mt-1 block text-xs text-red-600">{{ form.errors.password }}</span>
                        </label>
                        <label class="text-sm">Subdirectory (optional)
                            <input v-model="form.directory" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950" placeholder="uploads" />
                            <span v-if="form.errors.directory" class="mt-1 block text-xs text-red-600">{{ form.errors.directory }}</span>
                        </label>
                    </div>
                    <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-950">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Full directory path</p>
                        <p class="mt-1 break-all font-mono text-sm text-slate-700 dark:text-slate-200">{{ fullDirectoryPath || (form.website_id ? 'Website root path unavailable' : 'Select a website first') }}</p>
                    </div>
                    <button :disabled="form.processing || !form.website_id" class="mt-5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{{ form.processing ? 'Creating…' : 'Create FTP account' }}</button>
                </form>
                <aside class="h-fit rounded-xl border border-slate-200 bg-white p-5 text-sm dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Client connects with</h2>
                    <dl class="mt-3 space-y-2">
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Host</dt><dd class="font-mono">{{ connection.host }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Port</dt><dd class="font-mono">{{ connection.port }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Security</dt><dd>{{ securityLabel(connection) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Username</dt><dd class="font-mono">{{ form.username || '—' }}</dd></div>
                    </dl>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
