<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
    // A secondary (slave) zone copies everything from these servers (M7).
    primaries: props.zone?.primaries ?? '',
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

const statusOptions = computed(() => [{ value: 'active', label: t('dns.zoneSettings.active') }, { value: 'disabled', label: t('dns.zoneSettings.disabledOpt') }]);
const zoneTypeOptions = computed(() => [{ value: 'master', label: t('dns.zoneSettings.master') }, { value: 'slave', label: t('dns.zoneSettings.slave') }]);
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
                <h2 class="text-base font-semibold">{{ editing ? t('dns.zoneSettings.editTitle', { domain: zone.domain }) : t('dns.zoneSettings.addTitle') }}</h2>
                <p class="text-xs text-slate-500">{{ editing ? t('dns.zoneSettings.editSub') : t('dns.zoneSettings.addSub') }}</p>
            </div>
            <button type="button" :title="t('common.close')" class="h-9 w-9 rounded-md border border-slate-300 text-lg dark:border-slate-700" @click="emit('close')">×</button>
        </div>

        <div v-if="editing">
            <label class="mb-1 block text-sm">{{ t('dns.zoneSettings.domain') }}</label>
            <input v-model="form.domain" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        </div>
        <div v-else>
            <label class="mb-1 block text-sm font-medium">{{ t('dns.zoneSettings.domains') }}</label>
            <textarea v-model="form.domain" rows="5" autofocus spellcheck="false" placeholder="example.com&#10;example.org&#10;shop.example.net" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
            <p class="mt-1 text-xs text-slate-500">{{ t('dns.zoneSettings.onePerLine') }} {{ domains.length ? (domains.length === 1 ? t('dns.zoneSettings.toAddOne') : t('dns.zoneSettings.toAddMany', { count: domains.length })) : '' }}</p>
        </div>
        <p v-if="form.errors.domain" class="-mt-2 text-xs text-red-600 dark:text-red-400">{{ form.errors.domain }}</p>

        <label v-if="!editing" class="flex items-start gap-2 rounded-md border border-slate-200 p-3 text-sm dark:border-slate-700">
            <input v-model="scanAfterCreate" type="checkbox" class="mt-0.5 rounded border-slate-300" />
            <span>{{ t('dns.zoneSettings.findExisting') }}<span class="block text-xs text-slate-500">{{ domains.length > 1 ? t('dns.zoneSettings.findHelpMany') : t('dns.zoneSettings.findHelp') }}</span></span>
        </label>

        <button v-if="!editing" type="button" class="justify-self-start text-xs text-blue-600 hover:underline" @click="advanced = !advanced">{{ advanced ? t('dns.zoneSettings.hideAdvanced') : t('dns.zoneSettings.showAdvanced') }}</button>
        <template v-if="advanced">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-sm">{{ t('dns.zoneSettings.type') }}</label>
                    <SearchableSelect v-model="form.type" :options="zoneTypeOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('dns.zoneSettings.status') }}</label>
                    <SearchableSelect v-model="form.status" :options="statusOptions" />
                </div>
            </div>
            <div v-if="form.type === 'slave'">
                <label class="mb-1 block text-sm">{{ t('dns.zoneSettings.primaries') }}</label>
                <input v-model="form.primaries" type="text" placeholder="203.0.113.10, 2001:db8::10" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" />
                <p class="mt-1 text-xs text-slate-500">{{ t('dns.zoneSettings.primariesHelp') }}</p>
                <p v-if="form.errors.primaries || form.errors.type" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.primaries || form.errors.type }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm">{{ t('dns.zoneSettings.soaEmail') }}</label>
                <input v-model="form.email" type="email" :placeholder="t('dns.zoneSettings.soaEmailPlaceholder')" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.email }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div v-for="[field, label, min, max] in [['refresh', t('dns.zoneSettings.refresh'), 300, 86400], ['retry', t('dns.zoneSettings.retry'), 60, 86400], ['expire', t('dns.zoneSettings.expire'), 3600, 2592000], ['minimum_ttl', t('dns.zoneSettings.minimumTtl'), 60, 86400]]" :key="field">
                    <label class="mb-1 block text-sm">{{ label }}</label>
                    <input v-model.number="form[field]" type="number" :min="min" :max="max" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors[field]" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors[field] }}</p>
                </div>
            </div>
        </template>

        <p v-if="error && !form.errors.domain" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</p>
        <div class="flex items-center gap-2">
            <button type="submit" :disabled="form.processing || !domains.length" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                {{ form.processing ? t('dns.zoneSettings.saving') : editing ? t('dns.zoneSettings.saveZone') : domains.length > 1 ? t('dns.zoneSettings.addMany', { count: domains.length }) : t('dns.zoneSettings.addOne') }}
            </button>
            <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('close')">{{ t('common.cancel') }}</button>
        </div>

        <div v-if="editing && zone.can_transfer" class="mt-2 border-t border-slate-200 pt-5 dark:border-slate-700">
            <h3 class="text-sm font-semibold">{{ t('dns.zoneSettings.transfer') }}</h3>
            <p class="mb-3 text-xs text-slate-500">{{ t('dns.zoneSettings.transferHelp') }}</p>
            <SearchableSelect v-model="transferForm.owner_user_id" :options="transferUserOptions" :placeholder="t('dns.zoneSettings.selectUser')" :search-placeholder="t('dns.zoneSettings.searchUsers')" />
            <button type="button" :disabled="transferForm.processing || !transferForm.owner_user_id" class="mt-3 rounded-md border border-amber-400 px-4 py-2 text-sm font-medium text-amber-700 disabled:opacity-50 dark:text-amber-300" @click="transfer">{{ t('dns.zoneSettings.transferZone') }}</button>
        </div>
    </form>
</template>
