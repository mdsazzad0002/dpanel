<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    records: { type: Array, default: () => [] },
    zoneDomains: { type: Array, default: () => [] },
});

const page = usePage();
const editingId = ref(null);
const deleteForm = useForm({});

const form = useForm({
    zone_domain: '',
    type: 'A',
    name: '',
    content: '',
    ttl: 3600,
    priority: null,
    status: 'active',
});

const submit = () => {
    if (editingId.value) {
        form.patch(route('dns.records.update', editingId.value), { onSuccess: resetForm });
        return;
    }
    form.post(route('dns.records.store'), { onSuccess: resetForm });
};

const editItem = (item) => {
    editingId.value = item.id;
    form.zone_domain = item.zone_domain ?? '';
    form.type = item.type ?? 'A';
    form.name = item.name ?? '';
    form.content = item.content ?? '';
    form.ttl = Number(item.ttl ?? 3600);
    form.priority = item.priority ?? null;
    form.status = item.status ?? 'active';
};

const resetForm = () => {
    editingId.value = null;
    form.reset();
    form.type = 'A';
    form.ttl = 3600;
    form.status = 'active';
    form.priority = null;
};

const deleteItem = (id) => {
    if (!confirm('Delete this record?')) return;
    deleteForm.delete(route('dns.records.destroy', id));
};
</script>

<template>
    <Head title="DNS Records" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">DNS Records</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage A, CNAME, MX, TXT and other DNS records.</p>
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
                    <label class="mb-1 block text-sm">Zone Domain</label>
                    <select v-model="form.zone_domain" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Select zone</option>
                        <option v-for="domain in zoneDomains" :key="domain" :value="domain">{{ domain }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Type</label>
                    <select v-model="form.type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="A">A</option>
                        <option value="AAAA">AAAA</option>
                        <option value="CNAME">CNAME</option>
                        <option value="MX">MX</option>
                        <option value="TXT">TXT</option>
                        <option value="NS">NS</option>
                        <option value="SRV">SRV</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Name</label>
                    <input v-model="form.name" type="text" placeholder="@ or www" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Content</label>
                    <input v-model="form.content" type="text" placeholder="IP, hostname, or text value" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">TTL</label>
                    <input v-model.number="form.ttl" type="number" min="60" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Priority (MX/SRV)</label>
                    <input v-model.number="form.priority" type="number" min="0" max="65535" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
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
                        {{ editingId ? 'Update Record' : 'Create Record' }}
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
                            <th class="px-4 py-3">Zone</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Content</th>
                            <th class="px-4 py-3">TTL</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in records" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ item.zone_domain }}</td>
                            <td class="px-4 py-3">{{ item.type }}</td>
                            <td class="px-4 py-3">{{ item.name }}</td>
                            <td class="px-4 py-3">{{ item.content }}</td>
                            <td class="px-4 py-3">{{ item.ttl }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="editItem(item)">Edit</button>
                                    <button type="button" class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" @click="deleteItem(item.id)">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="records.length === 0">
                            <td colspan="10" class="px-4 py-6 text-center text-slate-500">No DNS records found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
