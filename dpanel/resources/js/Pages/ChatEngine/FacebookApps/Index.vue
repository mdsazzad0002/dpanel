<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    apps: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
    ownershipOptions: { type: Array, default: () => [] },
});

const toggleForm = useForm({});
const deleteForm = useForm({});
const search = ref('');
const ownerFilter = ref('');
const statusFilter = ref('');
const filteredApps = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return props.apps.filter((app) => (
        (!needle || [app.name, app.app_id, app.owner?.name, app.owner?.email]
            .some((value) => String(value || '').toLowerCase().includes(needle)))
        && (!ownerFilter.value || String(app.owner?.id || '') === ownerFilter.value)
        && (!statusFilter.value || (statusFilter.value === 'active' ? app.is_active : !app.is_active))
    ));
});

const toggle = (a) => {
    toggleForm.patch(panelRoute('chat-engine.facebook-apps.toggle', { facebookApp: a.id }));
};

const remove = (a) => {
    if (!confirm(`Remove Facebook App "${a.name}"? Pages already connected through it will keep working, but you won't be able to auto-connect more through it.`)) return;
    deleteForm.delete(panelRoute('chat-engine.facebook-apps.destroy', { facebookApp: a.id }));
};
const transferOwnership = (app, event) => {
    const ownerId = Number(event.target.value);
    if (!ownerId || ownerId === Number(app.owner?.id)) return;
    if (!confirm(`Transfer "${app.name}" and all of its connected Pages to the selected owner?`)) {
        event.target.value = String(app.owner?.id || '');
        return;
    }
    router.patch(panelRoute('chat-engine.facebook-apps.owner', { facebookApp: app.id }), { owner_id: ownerId }, { preserveScroll: true });
};

const panelOpen = ref(false);
const createForm = useForm({ name: '', app_id: '', app_secret: '' });

const submitCreate = () => {
    createForm.post(panelRoute('chat-engine.facebook-apps.store'), {
        preserveScroll: true,
        onSuccess: () => {
            panelOpen.value = false;
            createForm.reset();
        },
    });
};

const copied = ref(null);
const copy = async (text, key) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => { if (copied.value === key) copied.value = null; }, 1500);
    } catch (e) { /* clipboard unavailable — ignore */ }
};
</script>

<template>
    <Head title="Facebook Apps" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">Facebook Apps</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Register a Facebook App once, then auto-connect every Page you manage under it.</p>
                </div>
                <button @click="panelOpen = true" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">+ Register App</button>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="apps.length" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3 dark:border-slate-700 dark:bg-slate-800">
                <input v-model="search" type="search" placeholder="Search app, App ID or owner…" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <select v-model="ownerFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">All owners</option>
                    <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
                </select>
                <select v-model="statusFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div v-if="!apps.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                No Facebook Apps registered yet.
                <button @click="panelOpen = true" class="ml-1 text-blue-600 hover:underline">Register your first app</button>.
            </div>

            <div v-if="filteredApps.length" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="a in filteredApps" :key="a.id" class="flex flex-col rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-2">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-lg text-blue-600 dark:bg-slate-700">
                                <i class="bi bi-facebook"></i>
                            </span>
                            <div>
                                <p class="font-medium leading-tight">{{ a.name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">App ID: {{ a.app_id }}</p>
                                <p v-if="a.owner" class="mt-0.5 text-xs text-slate-400">Owner: {{ a.owner.name }}</p>
                            </div>
                        </div>
                        <button @click="toggle(a)" class="rounded px-2 py-0.5 text-xs font-medium" :class="a.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">
                            {{ a.is_active ? 'Active' : 'Inactive' }}
                        </button>
                    </div>

                    <div class="mt-4 rounded-md bg-slate-50 p-2 text-center dark:bg-slate-900/40">
                        <p class="text-base font-semibold">{{ a.channels_count }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Pages connected</p>
                    </div>

                    <div class="mt-3 space-y-2 text-xs">
                        <div>
                            <p class="text-slate-500 dark:text-slate-400">Callback URL — unique to this app, set once in its Meta dashboard</p>
                            <div class="mt-1 flex items-center gap-1">
                                <code class="flex-1 truncate rounded bg-slate-100 px-2 py-1 dark:bg-slate-900">{{ a.webhook_url }}</code>
                                <button @click="copy(a.webhook_url, `url-${a.id}`)" class="shrink-0 rounded border border-slate-300 px-1.5 py-1 hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">
                                    {{ copied === `url-${a.id}` ? '✓' : 'Copy' }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <p class="text-slate-500 dark:text-slate-400">Verify Token</p>
                            <div class="mt-1 flex items-center gap-1">
                                <code class="flex-1 truncate rounded bg-slate-100 px-2 py-1 dark:bg-slate-900">{{ a.verify_token }}</code>
                                <button @click="copy(a.verify_token, `vt-${a.id}`)" class="shrink-0 rounded border border-slate-300 px-1.5 py-1 hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">
                                    {{ copied === `vt-${a.id}` ? '✓' : 'Copy' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="ownershipOptions.length > 1" class="mt-3">
                        <label class="mb-1 block text-xs text-slate-500 dark:text-slate-400">Assign owner</label>
                        <select :value="String(a.owner?.id || '')" @change="transferOwnership(a, $event)" class="w-full rounded-md border-slate-300 py-1.5 text-xs dark:border-slate-600 dark:bg-slate-900">
                            <option v-for="owner in ownershipOptions" :key="owner.id" :value="String(owner.id)">{{ owner.name }} — {{ owner.email }}</option>
                        </select>
                        <p class="mt-1 text-[11px] text-slate-400">Connected Page channels transfer together.</p>
                    </div>

                    <div class="mt-4 flex flex-1 items-end gap-2">
                        <Link
                            :href="panelRoute('chat-engine.facebook-apps.connect', { facebookApp: a.id })"
                            class="flex-1 rounded-md bg-blue-600 px-3 py-1.5 text-center text-xs font-medium text-white hover:bg-blue-700"
                        ><i class="bi bi-facebook"></i> Connect via Facebook</Link>
                        <button @click="remove(a)" class="rounded-md border border-red-300 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:border-red-700" title="Remove">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div v-else-if="apps.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">No Facebook Apps match the selected filters.</div>
        </div>

        <Offcanvas :show="panelOpen" title="Register Facebook App" subtitle="App ID + App Secret from the Meta developer dashboard" width="md" @close="panelOpen = false">
            <form @submit.prevent="submitCreate" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">Name</label>
                    <input v-model="createForm.name" type="text" placeholder="My Support App" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <p v-if="createForm.errors.name" class="mt-1 text-xs text-red-600">{{ createForm.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">App ID</label>
                    <input v-model="createForm.app_id" type="text" placeholder="1234567890123456" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono dark:border-slate-600 dark:bg-slate-900" />
                    <p v-if="createForm.errors.app_id" class="mt-1 text-xs text-red-600">{{ createForm.errors.app_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">App Secret</label>
                    <input v-model="createForm.app_secret" type="text" placeholder="From App Dashboard → Settings → Basic" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono dark:border-slate-600 dark:bg-slate-900" />
                    <p v-if="createForm.errors.app_secret" class="mt-1 text-xs text-red-600">{{ createForm.errors.app_secret }}</p>
                </div>

                <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    After registering, this app's Callback URL and Verify Token appear on its card — paste both into the
                    Meta dashboard's Webhooks setup before clicking "Connect via Facebook".
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
                    <button type="button" @click="panelOpen = false" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">Cancel</button>
                    <button type="submit" :disabled="createForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Register App</button>
                </div>
            </form>
        </Offcanvas>
    </AuthenticatedLayout>
</template>
