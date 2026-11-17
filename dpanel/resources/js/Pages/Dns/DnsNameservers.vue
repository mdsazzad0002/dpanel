<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    nameservers: { type: Array, default: () => [] },
    websiteDomains: { type: Array, default: () => [] },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const websiteDomainOptions = computed(() => props.websiteDomains.map((domain) => ({ value: domain, label: domain })));
const statusOptions = computed(() => [
    { value: 'active', label: t('dns.nameservers.statuses.active') },
    { value: 'disabled', label: t('dns.nameservers.statuses.disabled') },
]);

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
        form.patch(panelRoute('dns.nameservers.update', { id: editingId.value }), { onSuccess: resetForm });
        return;
    }

    form.post(panelRoute('dns.nameservers.store'), { onSuccess: resetForm });
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
    if (!confirm(t('dns.nameservers.deleteConfirm'))) return;
    deleteForm.delete(panelRoute('dns.nameservers.destroy', { id }));
};
</script>

<template>
    <Head :title="t('dns.nameservers.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('dns.nameservers.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('dns.nameservers.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>
            <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                {{ t('dns.nameservers.notice') }}
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">{{ t('dns.nameservers.domain') }}</label>
                    <SearchableSelect
                        v-model="form.domain"
                        :options="websiteDomainOptions"
                        :placeholder="t('dns.nameservers.selectDomain')"
                        :search-placeholder="t('dns.nameservers.searchDomains')"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('dns.nameservers.hostname') }}</label>
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
                    <label class="mb-1 block text-sm">{{ t('dns.nameservers.status') }}</label>
                    <SearchableSelect v-model="form.status" :options="statusOptions" />
                </div>
                <div class="md:col-span-3 flex items-center gap-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ editingId ? t('dns.nameservers.update') : t('dns.nameservers.create') }}
                    </button>
                    <button v-if="editingId" type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="resetForm">
                        {{ t('common.cancel') }}
                    </button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">{{ t('dns.nameservers.domain') }}</th>
                            <th class="px-4 py-3">{{ t('dns.nameservers.hostname') }}</th>
                            <th class="px-4 py-3">IPv4</th>
                            <th class="px-4 py-3">IPv6</th>
                            <th class="px-4 py-3">TTL</th>
                            <th class="px-4 py-3">{{ t('dns.nameservers.status') }}</th>
                            <th class="px-4 py-3">{{ t('dns.nameservers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in nameservers" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ item.domain }}</td>
                            <td class="px-4 py-3">{{ item.hostname }}</td>
                            <td class="px-4 py-3">{{ item.ipv4 || '-' }}</td>
                            <td class="px-4 py-3">{{ item.ipv6 || '-' }}</td>
                            <td class="px-4 py-3">{{ item.ttl }}</td>
                            <td class="px-4 py-3">{{ t(`dns.nameservers.statuses.${item.status}`) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="editItem(item)">{{ t('dns.nameservers.edit') }}</button>
                                    <button type="button" class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" @click="deleteItem(item.id)">{{ t('dns.nameservers.delete') }}</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="nameservers.length === 0">
                            <td colspan="10" class="px-4 py-6 text-center text-slate-500">{{ t('dns.nameservers.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
