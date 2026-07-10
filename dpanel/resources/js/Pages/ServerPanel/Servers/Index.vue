<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ServerFormFields from '@/Components/ServerPanel/ServerFormFields.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    servers: { type: Array, default: () => [] },
    statusCounts: { type: Object, default: () => ({}) },
});

const drawerOpen = ref(false);
const editingServer = ref(null);

const form = useForm({
    name: '',
    host: '',
    port: 22,
    username: '',
    auth_type: 'password',
    password: '',
    private_key: '',
    private_key_passphrase: '',
    mode: 'setup',
    notes: '',
});

const openCreate = () => {
    editingServer.value = null;
    form.defaults({
        name: '',
        host: '',
        port: 22,
        username: '',
        auth_type: 'password',
        password: '',
        private_key: '',
        private_key_passphrase: '',
        mode: 'setup',
        notes: '',
    });
    form.reset();
    drawerOpen.value = true;
};

const openEdit = (server) => {
    editingServer.value = server;
    form.defaults({
        name: server.name ?? '',
        host: server.host ?? '',
        port: server.port ?? 22,
        username: server.username ?? '',
        auth_type: server.auth_type ?? 'password',
        password: '',
        private_key: '',
        private_key_passphrase: '',
        mode: server.mode ?? 'setup',
        notes: server.notes ?? '',
    });
    form.reset();
    drawerOpen.value = true;
};

const closeDrawer = () => {
    drawerOpen.value = false;
    editingServer.value = null;
    form.clearErrors();
};

const submit = () => {
    if (editingServer.value) {
        form.patch(route('servers.update', editingServer.value.id), {
            preserveScroll: true,
            onSuccess: () => closeDrawer(),
        });

        return;
    }

    form.post(route('servers.store'), {
        preserveScroll: true,
        onSuccess: () => closeDrawer(),
    });
};

const runAction = (name, serverId) => {
    const routeName = name === 'test' ? 'servers.test-connection' : 'servers.scan';
    router.post(route(routeName, serverId));
};
</script>

<template>
    <Head title="Servers" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">Servers</h1>
                    <p class="text-sm text-slate-500">Simple server list and quick actions</p>
                </div>
                <button type="button" class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800" @click="openCreate">Add Server</button>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">Online</p><p class="text-2xl font-semibold">{{ statusCounts.online || 0 }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">Errors</p><p class="text-2xl font-semibold">{{ statusCounts.error || 0 }}</p></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs uppercase text-slate-500">Failed Commands</p><p class="text-2xl font-semibold">{{ statusCounts.failed || 0 }}</p></div>
            </section>

            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="server in servers" :key="server.id" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between">
                        <div>
                            <h2 class="text-base font-semibold">{{ server.name }}</h2>
                            <p class="text-sm text-slate-600">{{ server.host }}:{{ server.port }} as {{ server.username }}</p>
                        </div>
                        <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="server.status === 'online' ? 'bg-emerald-100 text-emerald-700' : (server.status === 'error' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-700')">{{ server.status }}</span>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">Last Connected: {{ server.last_connected_at || '-' }}</p>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                        <button type="button" class="rounded border border-slate-300 px-2 py-2 hover:bg-slate-50" @click="runAction('test', server.id)">Test Connection</button>
                        <button type="button" class="rounded border border-slate-300 px-2 py-2 hover:bg-slate-50" @click="runAction('scan', server.id)">Scan</button>
                        <Link :href="route('servers.commands', server.id)" class="col-span-2 rounded border border-slate-300 px-2 py-2 text-center hover:bg-slate-50">Open Commands</Link>
                    </div>
                    <div class="mt-2 flex items-center gap-3 text-xs">
                        <Link :href="route('servers.show', server.id)" class="text-xs font-semibold text-cyan-700">Open Details</Link>
                        <button type="button" class="text-xs font-semibold text-slate-700" @click="openEdit(server)">Edit</button>
                    </div>
                </article>
            </section>
        </div>

        <div v-if="drawerOpen" class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-slate-900/50" @click="closeDrawer" />
            <aside class="absolute right-0 top-0 h-full w-full overflow-y-auto bg-white p-6 shadow-2xl lg:w-[70%]">
                <div class="mb-4 flex items-start justify-between">
                    <div>
                        <h2 class="text-lg font-semibold">{{ editingServer ? 'Edit Server' : 'Add Server' }}</h2>
                        <p class="text-sm text-slate-500">Credentials are encrypted and never shown again.</p>
                    </div>
                    <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs" @click="closeDrawer">Close</button>
                </div>

                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submit">
                    <ServerFormFields :form="form" />

                    <div class="md:col-span-2 flex justify-end gap-2">
                        <button type="button" class="rounded border border-slate-300 px-4 py-2 text-sm" @click="closeDrawer">Cancel</button>
                        <button type="submit" class="rounded bg-cyan-700 px-4 py-2 text-sm font-semibold text-white" :disabled="form.processing">
                            {{ editingServer ? 'Update Server' : 'Save Server' }}
                        </button>
                    </div>
                </form>
            </aside>
        </div>
    </AuthenticatedLayout>
</template>
