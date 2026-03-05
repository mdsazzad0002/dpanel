<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    nameservers: { type: Array, default: () => [] },
    websiteDomains: { type: Array, default: () => [] },
});

const page = usePage();
const editingId = ref(null);
const deleteForm = useForm({});

const form = useForm({
    domain: '',
    hostname: '',
    ipv4: '',
    ipv6: '',
    ttl: 3600,
    status: 'active',
});

const submit = () => {
    if (editingId.value) {
        form.patch(route('dns.nameservers.update', editingId.value), { onSuccess: resetForm });
        return;
    }

    form.post(route('dns.nameservers.store'), { onSuccess: resetForm });
};

const editItem = (item) => {
    editingId.value = item.id;
    form.domain = item.domain ?? '';
    form.hostname = item.hostname ?? '';
    form.ipv4 = item.ipv4 ?? '';
    form.ipv6 = item.ipv6 ?? '';
    form.ttl = Number(item.ttl ?? 3600);
    form.status = item.status ?? 'active';
};

const resetForm = () => {
    editingId.value = null;
    form.reset();
    form.ttl = 3600;
    form.status = 'active';
};

const deleteItem = (id) => {
    if (!confirm('Delete this nameserver?')) return;
    deleteForm.delete(route('dns.nameservers.destroy', id));
};
</script>

<template>
    <Head title="DNS Nameservers" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">DNS Nameservers</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage nameserver records for hosted domains.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Domain</label>
                    <select v-model="form.domain" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Select domain</option>
                        <option v-for="domain in websiteDomains" :key="domain" :value="domain">{{ domain }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Hostname</label>
                    <input v-model="form.hostname" type="text" placeholder="ns1.example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">IPv4</label>
                    <input v-model="form.ipv4" type="text" placeholder="192.168.0.10" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">IPv6</label>
                    <input v-model="form.ipv6" type="text" placeholder="2001:db8::1" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">TTL</label>
                    <input v-model.number="form.ttl" type="number" min="60" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Status</label>
                    <select v-model="form.status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="active">active</option>
                        <option value="disabled">disabled</option>
                    </select>
                </div>
                <div class="md:col-span-3 flex items-center gap-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ editingId ? 'Update Nameserver' : 'Create Nameserver' }}
                    </button>
                    <button v-if="editingId" type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="resetForm">
                        Cancel
                    </button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Domain</th>
                            <th class="px-4 py-3">Hostname</th>
                            <th class="px-4 py-3">IPv4</th>
                            <th class="px-4 py-3">TTL</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in nameservers" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ item.domain }}</td>
                            <td class="px-4 py-3">{{ item.hostname }}</td>
                            <td class="px-4 py-3">{{ item.ipv4 || '-' }}</td>
                            <td class="px-4 py-3">{{ item.ttl }}</td>
                            <td class="px-4 py-3">{{ item.status }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="editItem(item)">Edit</button>
                                    <button type="button" class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" @click="deleteItem(item.id)">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="nameservers.length === 0">
                            <td colspan="10" class="px-4 py-6 text-center text-slate-500">No nameserver records found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
