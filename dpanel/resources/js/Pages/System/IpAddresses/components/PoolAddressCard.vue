<script setup>
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';

// One address of the pool: shared or dedicated, its NAT address, the site
// a bare-IP request shows, and what uses it. A reseller only picks which of
// their customers gets the address.
const props = defineProps({
    row: { type: Object, required: true },
    isAdmin: { type: Boolean, default: false },
    owners: { type: Object, default: () => ({ resellers: [], users: [] }) },
});
const emit = defineEmits(['save', 'main', 'remove']);
const { t } = useI18n();

const form = reactive({
    mode: props.row.mode,
    public_address: props.row.public_address || '',
    label: props.row.label || '',
    reseller_id: props.row.reseller_id || '',
    user_id: props.row.user_id || '',
    default_website_id: props.row.default_website_id || '',
});
const users = computed(() => props.owners.users.filter((user) => !form.reseller_id || user.reseller_id === Number(form.reseller_id)));
const nullable = (value) => (value === '' ? null : value);

const save = () => {
    if (!props.isAdmin) {
        emit('save', { user_id: nullable(form.user_id) });
        return;
    }
    emit('save', {
        mode: form.mode,
        public_address: nullable(form.public_address),
        label: nullable(form.label),
        reseller_id: form.mode === 'dedicated' ? nullable(form.reseller_id) : null,
        user_id: form.mode === 'dedicated' ? nullable(form.user_id) : null,
        default_website_id: nullable(form.default_website_id),
    });
};
const field = 'rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-base font-semibold">{{ row.address }}</span>
            <span v-if="row.public_address" class="font-mono text-sm text-slate-500">→ {{ row.public_address }}</span>
            <span v-if="row.is_main" class="rounded bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">{{ t('ipPool.main') }}</span>
            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-800">{{ row.mode === 'dedicated' ? t('ipPool.dedicatedTo', { owner: row.user || row.reseller || '?' }) : t('ipPool.shared') }}</span>
            <span v-if="row.missing" class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('ipPool.missing') }}</span>
            <span v-if="row.label" class="text-sm text-slate-500">{{ row.label }}</span>
        </div>
        <p class="mt-1 text-xs text-slate-500">
            {{ t('ipPool.usage', { websites: row.websites.length }) }}
            <template v-if="row.mail"> · {{ t('ipPool.mailUsage', { host: row.mail.hostname || '-', domains: row.mail.is_default ? t('ipPool.mailDefault') : row.mail.domains }) }}</template>
        </p>

        <form class="mt-3 grid gap-2 sm:grid-cols-3" @submit.prevent="save">
            <template v-if="isAdmin">
                <label class="text-xs text-slate-500">{{ t('ipPool.mode') }}
                    <select v-model="form.mode" :class="field" class="mt-1 w-full" :disabled="row.is_main">
                        <option value="shared">{{ t('ipPool.shared') }}</option>
                        <option value="dedicated">{{ t('ipPool.dedicated') }}</option>
                    </select>
                </label>
                <label v-if="form.mode === 'dedicated'" class="text-xs text-slate-500">{{ t('ipPool.reseller') }}
                    <select v-model="form.reseller_id" :class="field" class="mt-1 w-full">
                        <option value="">{{ t('ipPool.none') }}</option>
                        <option v-for="reseller in owners.resellers" :key="reseller.id" :value="reseller.id">{{ reseller.name }}</option>
                    </select>
                </label>
            </template>
            <label v-if="!isAdmin || form.mode === 'dedicated'" class="text-xs text-slate-500">{{ t('ipPool.user') }}
                <select v-model="form.user_id" :class="field" class="mt-1 w-full">
                    <option value="">{{ isAdmin ? t('ipPool.none') : t('ipPool.keep') }}</option>
                    <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                </select>
            </label>
            <template v-if="isAdmin">
                <label class="text-xs text-slate-500">{{ t('ipPool.publicAddress') }}
                    <input v-model="form.public_address" :class="field" class="mt-1 w-full font-mono" :title="t('ipPool.publicHelp')" />
                </label>
                <label class="text-xs text-slate-500">{{ t('ipPool.label') }}
                    <input v-model="form.label" :class="field" class="mt-1 w-full" />
                </label>
                <label class="text-xs text-slate-500">{{ t('ipPool.defaultSite') }}
                    <select v-model="form.default_website_id" :class="field" class="mt-1 w-full" :title="t('ipPool.defaultSiteHelp')">
                        <option value="">{{ t('ipPool.none') }}</option>
                        <option v-for="site in row.websites" :key="site.id" :value="site.id">{{ site.domain }}</option>
                    </select>
                </label>
            </template>
            <div class="flex flex-wrap items-end gap-2 sm:col-span-3">
                <button type="submit" class="rounded-md bg-sky-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-700">{{ t('ipPool.save') }}</button>
                <button v-if="isAdmin && !row.is_main && row.mode === 'shared'" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700" @click="emit('main')">{{ t('ipPool.makeMain') }}</button>
                <button v-if="isAdmin" type="button" class="rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-600 dark:border-red-900" @click="emit('remove')">{{ t('ipPool.remove') }}</button>
            </div>
        </form>
    </section>
</template>
