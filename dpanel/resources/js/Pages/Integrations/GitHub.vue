<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    configured: Boolean,
    isAdmin: Boolean,
    app: { type: Object, default: null },
    setup: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
});

const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);
const flash = computed(() => page.props.flash || {});

const editing = ref(!props.configured);
const showSecret = ref(false);
const copied = ref('');
const appForm = useForm({ name: props.app?.name || 'dPanel', client_id: props.app?.client_id || '', client_secret: '' });

const saveApp = () => appForm.put(panelRoute('github.app.save'), {
    preserveScroll: true,
    onSuccess: () => { appForm.client_secret = ''; editing.value = false; },
});
const removeApp = () => {
    if (!confirm('Remove the GitHub app configuration? Users will not be able to connect new GitHub accounts until it is set up again.')) return;
    router.delete(panelRoute('github.app.destroy'), { preserveScroll: true, onSuccess: () => { editing.value = true; appForm.reset(); } });
};
const disconnect = (account) => {
    const inUse = account.deployments_count ? `\n\n${account.deployments_count} website(s) use this account and will need another account or a token.` : '';
    if (!confirm(`Disconnect GitHub account @${account.login}?${inUse}`)) return;
    router.delete(panelRoute('github.accounts.destroy', { account: account.id }), { preserveScroll: true });
};
const copy = async (key, value) => {
    try { await navigator.clipboard.writeText(value); copied.value = key; window.setTimeout(() => { copied.value = ''; }, 1500); } catch (e) { /* selectable fallback */ }
};
const date = (value) => value ? new Date(value.replace(' ', 'T')).toLocaleString() : 'Never';
</script>

<template>
    <Head title="GitHub Integration" />
    <AuthenticatedLayout>
        <template #header>
            <div><h1 class="text-lg font-semibold">GitHub Integration</h1><p class="text-sm text-slate-500">Connect one or more GitHub accounts and deploy repositories to your websites</p></div>
        </template>

        <div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
            <div v-if="flash.success" role="status" class="flex gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800"><i class="bi bi-check-circle-fill"></i><span>{{ flash.success }}</span></div>
            <div v-if="flash.error" role="alert" class="flex gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800"><i class="bi bi-exclamation-triangle-fill"></i><span>{{ flash.error }}</span></div>

            <!-- Admin: first-time / ongoing GitHub app configuration -->
            <section v-if="isAdmin" class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-900 text-white dark:bg-slate-700"><i class="bi bi-github text-lg"></i></span>
                        <div>
                            <h2 class="font-semibold">GitHub app <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium" :class="configured ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">{{ configured ? 'Configured' : 'Setup required' }}</span></h2>
                            <p class="mt-1 text-sm text-slate-500">একবার admin OAuth app setup করলে সব user নিজের GitHub account connect করতে পারবে।</p>
                        </div>
                    </div>
                    <div v-if="configured && !editing" class="flex gap-2">
                        <button type="button" @click="editing = true" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700"><i class="bi bi-pencil mr-1"></i>Edit</button>
                        <button type="button" @click="removeApp" class="rounded-lg border border-red-300 px-3 py-2 text-sm text-red-600"><i class="bi bi-trash mr-1"></i>Remove</button>
                    </div>
                </div>

                <dl v-if="configured && !editing" class="mt-4 grid gap-3 border-t pt-4 text-sm sm:grid-cols-3 dark:border-slate-700">
                    <div><dt class="text-xs text-slate-500">Name</dt><dd class="font-medium">{{ app?.name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Client ID</dt><dd class="break-all font-mono text-xs">{{ app?.client_id }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Last updated</dt><dd>{{ date(app?.updated_at) }}</dd></div>
                </dl>

                <div v-if="editing" class="mt-5 grid gap-6 lg:grid-cols-2">
                    <ol class="space-y-3 text-sm">
                        <li class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><strong>1.</strong> GitHub → Settings → Developer settings → <strong>OAuth Apps</strong> → New OAuth App (অথবা organization-এর settings থেকে)।</li>
                        <li class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800">
                            <strong>2.</strong> নিচের URL দুটো ব্যবহার করুন:
                            <div v-for="item in [{ key: 'home', label: 'Homepage URL', value: setup.homepage_url }, { key: 'cb', label: 'Authorization callback URL', value: setup.callback_url }]" :key="item.key" class="mt-2">
                                <p class="text-xs text-slate-500">{{ item.label }}</p>
                                <div class="mt-1 flex gap-2"><code class="min-w-0 flex-1 break-all rounded bg-white px-2 py-1.5 text-xs dark:bg-slate-900">{{ item.value }}</code><button type="button" @click="copy(item.key, item.value)" class="shrink-0 rounded border px-2 text-xs dark:border-slate-600"><i class="bi" :class="copied === item.key ? 'bi-check-lg' : 'bi-copy'"></i></button></div>
                            </div>
                        </li>
                        <li class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><strong>3.</strong> App তৈরি হলে <em>Client ID</em> এবং নতুন <em>Client secret</em> এখানে paste করুন। Requested scopes: <code v-for="scope in setup.scopes" :key="scope" class="ml-1 rounded bg-white px-1 text-xs dark:bg-slate-900">{{ scope }}</code></li>
                    </ol>
                    <form @submit.prevent="saveApp" class="space-y-4">
                        <label class="block text-sm font-medium">Display name<input v-model.trim="appForm.name" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" placeholder="dPanel" /></label>
                        <label class="block text-sm font-medium">Client ID <span class="text-red-500">*</span><input v-model.trim="appForm.client_id" required class="mt-1.5 w-full rounded-lg border-slate-300 font-mono dark:bg-slate-800" placeholder="Ov23li…" /><span v-if="appForm.errors.client_id" class="mt-1 block text-xs text-red-600">{{ appForm.errors.client_id }}</span></label>
                        <label class="block text-sm font-medium">Client secret <span v-if="!configured" class="text-red-500">*</span>
                            <div class="relative mt-1.5"><input v-model="appForm.client_secret" :type="showSecret ? 'text' : 'password'" :required="!configured" autocomplete="new-password" class="w-full rounded-lg border-slate-300 pr-16 dark:bg-slate-800" :placeholder="app?.has_secret ? 'Saved — blank রাখলে আগেরটি থাকবে' : 'Paste client secret'" /><button type="button" @click="showSecret = !showSecret" class="absolute right-2 top-1/2 -translate-y-1/2 px-2 text-xs text-indigo-600">{{ showSecret ? 'Hide' : 'Show' }}</button></div>
                            <span v-if="appForm.errors.client_secret" class="mt-1 block text-xs text-red-600">{{ appForm.errors.client_secret }}</span>
                            <span class="mt-1 block text-xs font-normal text-slate-500">Secret encrypted অবস্থায় database-এ থাকে।</span>
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="appForm.processing" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-40">{{ appForm.processing ? 'Saving…' : configured ? 'Update app' : 'Save & enable GitHub' }}</button>
                            <button v-if="configured" type="button" @click="editing = false; appForm.reset(); appForm.clearErrors()" class="rounded-lg border px-4 py-2.5 text-sm dark:border-slate-700">Cancel</button>
                        </div>
                    </form>
                </div>
            </section>

            <div v-else-if="!configured" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800"><i class="bi bi-hourglass-split mr-2"></i>GitHub integration এখনো setup হয়নি। Administrator GitHub app configure করলে এখানে account connect করতে পারবেন।</div>

            <!-- Every user: linked GitHub accounts -->
            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div><h2 class="font-semibold">Connected GitHub accounts</h2><p class="mt-1 text-sm text-slate-500">Personal, work অথবা client—যতগুলো দরকার account যোগ করুন। প্রতিটি website আলাদা account ব্যবহার করতে পারে।</p></div>
                    <a v-if="configured" :href="panelRoute('github.connect')" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 dark:bg-indigo-600"><i class="bi bi-plus-lg mr-2"></i>{{ accounts.length ? 'Connect another account' : 'Connect GitHub account' }}</a>
                </div>
                <p v-if="configured && accounts.length" class="mt-2 text-xs text-slate-500"><i class="bi bi-info-circle mr-1"></i>অন্য account যোগ করতে GitHub-এর account picker থেকে সেটি বেছে নিন (বা আগে github.com-এ সেই account-এ sign in করুন)।</p>

                <ul class="mt-4 divide-y dark:divide-slate-800">
                    <li v-for="account in accounts" :key="account.id" class="flex flex-wrap items-center gap-4 py-3">
                        <img v-if="account.avatar_url" :src="account.avatar_url" alt="" class="h-10 w-10 rounded-full" />
                        <span v-else class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800"><i class="bi bi-person"></i></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">@{{ account.login }} <span v-if="account.name" class="font-normal text-slate-500">· {{ account.name }}</span></p>
                            <p class="text-xs text-slate-500">{{ account.deployments_count || 0 }} website(s) · connected {{ date(account.connected_at) }} · last used {{ date(account.last_used_at) }}</p>
                        </div>
                        <a v-if="configured" :href="panelRoute('github.connect')" class="rounded-lg border px-3 py-2 text-xs dark:border-slate-700" title="Re-authorize to refresh the token or scopes"><i class="bi bi-arrow-repeat mr-1"></i>Reconnect</a>
                        <button type="button" @click="disconnect(account)" class="rounded-lg border border-red-300 px-3 py-2 text-xs text-red-600"><i class="bi bi-x-circle mr-1"></i>Disconnect</button>
                    </li>
                    <li v-if="!accounts.length" class="py-10 text-center"><i class="bi bi-github block text-3xl text-slate-300"></i><p class="mt-2 text-sm text-slate-500">No GitHub accounts connected yet</p></li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
