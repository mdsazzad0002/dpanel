<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SearchableSelect from '@/Components/SearchableSelect.vue';

/**
 * Adds one or more domains (zone = null), or edits one zone's SOA settings
 * and owner.
 */
const props = defineProps({
    zone: { type: Object, default: null },
    transferUsers: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['close', 'created']);

const editing = computed(() => props.zone !== null);
const form = useForm({
    domain: props.zone?.domain ?? '',
    type: props.zone?.type ?? 'master',
    email: props.zone?.email ?? '',
    refresh: Number(props.zone?.refresh ?? 3600),
    retry: Number(props.zone?.retry ?? 600),
    expire: Number(props.zone?.expire ?? 1209600),
    minimum_ttl: Number(props.zone?.minimum_ttl ?? 3600),
    status: props.zone?.status ?? 'active',
});
const transferForm = useForm({ owner_user_id: props.zone?.owner_user_id ?? '' });
const scanAfterCreate = ref(true);
const advanced = ref(editing.value);

const domains = computed(() => [...new Set(String(form.domain).toLowerCase().split(/[\s,;]+/)
    .map((domain) => domain.replace(/^https?:\/\//, '').replace(/\/.*$/, '').replace(/\.$/, '').trim())
    .filter(Boolean))]);
const error = computed(() => Object.values(form.errors)[0] || '');

const statusOptions = [{ value: 'active', label: 'active' }, { value: 'disabled', label: 'disabled' }];
const zoneTypeOptions = [{ value: 'master', label: 'master' }, { value: 'slave', label: 'slave' }];
const transferUserOptions = computed(() => props.transferUsers.map((user) => ({ value: user.id, label: `${user.name} · ${user.email}` })));

const submit = () => {
    const options = { preserveScroll: true, preserveState: true };
    if (editing.value) {
        form.domain = domains.value[0] || '';
        form.patch(props.panelRoute('dns.zones.update', { id: props.zone.zone_uuid || props.zone.id }), { ...options, onSuccess: () => emit('close') });
        return;
    }
    const created = domains.value;
    form.transform((data) => ({ ...data, domain: created.join('\n') }))
        .post(props.panelRoute('dns.zones.store'), {
            ...options,
            onSuccess: () => emit('created', { domains: created, scan: scanAfterCreate.value }),
        });
};

const transfer = () => {
    if (!transferForm.owner_user_id) return;
    transferForm.patch(props.panelRoute('dns.zones.transfer', { id: props.zone.zone_uuid || props.zone.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <div class="fixed inset-0 z-[60] bg-slate-950/40" @click="emit('close')"></div>
    <form class="fixed inset-y-0 right-0 z-[70] grid w-full max-w-lg content-start gap-4 overflow-y-auto bg-white p-6 shadow-2xl dark:bg-slate-900" @submit.prevent="submit">
        <div class="flex items-center justify-between border-b border-slate-200 pb-4 dark:border-slate-700">
            <div>
                <h2 class="text-base font-semibold">{{ editing ? `Zone settings · ${zone.domain}` : 'Add domains' }}</h2>
                <p class="text-xs text-slate-500">{{ editing ? 'SOA values, status and owner' : 'Each domain gets its own DNS zone' }}</p>
            </div>
            <button type="button" title="Close" class="h-9 w-9 rounded-md border border-slate-300 text-lg dark:border-slate-700" @click="emit('close')">×</button>
        </div>

        <div v-if="editing">
            <label class="mb-1 block text-sm">Domain</label>
            <input v-model="form.domain" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        </div>
        <div v-else>
            <label class="mb-1 block text-sm font-medium">Domains</label>
            <textarea v-model="form.domain" rows="5" autofocus spellcheck="false" placeholder="example.com&#10;example.org&#10;shop.example.net" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
            <p class="mt-1 text-xs text-slate-500">One per line, or separated by commas. {{ domains.length ? `${domains.length} domain${domains.length === 1 ? '' : 's'} to add.` : '' }}</p>
        </div>
        <p v-if="form.errors.domain" class="-mt-2 text-xs text-red-600 dark:text-red-400">{{ form.errors.domain }}</p>

        <label v-if="!editing" class="flex items-start gap-2 rounded-md border border-slate-200 p-3 text-sm dark:border-slate-700">
            <input v-model="scanAfterCreate" type="checkbox" class="mt-0.5 rounded border-slate-300" />
            <span>Find existing DNS records<span class="block text-xs text-slate-500">Scan public DNS for the domain's current records and pick which to import{{ domains.length > 1 ? ' (opens for the first domain; scan the others from their zone)' : '' }}.</span></span>
        </label>

        <button v-if="!editing" type="button" class="justify-self-start text-xs text-blue-600 hover:underline" @click="advanced = !advanced">{{ advanced ? 'Hide' : 'Show' }} advanced SOA settings</button>
        <template v-if="advanced">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-sm">Type</label>
                    <SearchableSelect v-model="form.type" :options="zoneTypeOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Status</label>
                    <SearchableSelect v-model="form.status" :options="statusOptions" />
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm">SOA email</label>
                <input v-model="form.email" type="email" placeholder="hostmaster@ + domain when empty" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.email }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div v-for="[field, label, min, max] in [['refresh', 'Refresh', 300, 86400], ['retry', 'Retry', 60, 86400], ['expire', 'Expire', 3600, 2592000], ['minimum_ttl', 'Minimum TTL', 60, 86400]]" :key="field">
                    <label class="mb-1 block text-sm">{{ label }}</label>
                    <input v-model.number="form[field]" type="number" :min="min" :max="max" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors[field]" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors[field] }}</p>
                </div>
            </div>
        </template>

        <p v-if="error && !form.errors.domain" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</p>
        <div class="flex items-center gap-2">
            <button type="submit" :disabled="form.processing || !domains.length" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                {{ form.processing ? 'Saving…' : editing ? 'Save zone' : `Add ${domains.length > 1 ? domains.length + ' domains' : 'domain'}` }}
            </button>
            <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('close')">Cancel</button>
        </div>

        <div v-if="editing && zone.can_transfer" class="mt-2 border-t border-slate-200 pt-5 dark:border-slate-700">
            <h3 class="text-sm font-semibold">Transfer ownership</h3>
            <p class="mb-3 text-xs text-slate-500">Creator history remains unchanged after transfer.</p>
            <SearchableSelect v-model="transferForm.owner_user_id" :options="transferUserOptions" placeholder="Select user" search-placeholder="Search users…" />
            <button type="button" :disabled="transferForm.processing || !transferForm.owner_user_id" class="mt-3 rounded-md border border-amber-400 px-4 py-2 text-sm font-medium text-amber-700 disabled:opacity-50 dark:text-amber-300" @click="transfer">Transfer zone</button>
        </div>
    </form>
</template>
