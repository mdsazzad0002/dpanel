<script setup>
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';

// The addresses on the server's interfaces (from drust), and the form that
// puts one into the pool. Without a list (drust down, or not supported on
// this system) the admin types the address.
defineProps({
    discovered: { type: Array, default: null },
    error: { type: String, default: null },
});
const emit = defineEmits(['add']);
const { t } = useI18n();

const form = reactive({ address: '', public_address: '', label: '', mode: 'shared' });
const pick = (found) => {
    form.address = found.address;
    form.public_address = '';
};
const submit = () => {
    emit('add', { ...form });
    form.address = '';
    form.public_address = '';
    form.label = '';
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-sm font-semibold">{{ t('ipPool.serverAddresses') }}</h2>
        <p v-if="error" class="mt-2 text-sm text-amber-700 dark:text-amber-400">{{ t('ipPool.discoveryFailed', { error }) }}</p>
        <table v-else-if="discovered" class="mt-3 w-full text-sm">
            <thead class="text-left text-xs uppercase text-slate-500">
                <tr><th class="py-1">{{ t('ipPool.address') }}</th><th>{{ t('ipPool.interface') }}</th><th>{{ t('ipPool.reach') }}</th><th></th></tr>
            </thead>
            <tbody>
                <tr v-for="found in discovered" :key="found.address" class="border-t border-slate-100 dark:border-slate-800">
                    <td class="py-1.5 font-mono">{{ found.address }}/{{ found.prefix }}</td>
                    <td>{{ found.interface }}</td>
                    <td>{{ found.public ? t('ipPool.public') : t('ipPool.private') }}</td>
                    <td class="text-right">
                        <span v-if="found.in_pool" class="text-xs text-slate-500">{{ t('ipPool.inPool') }}</span>
                        <button v-else type="button" class="text-xs font-semibold text-sky-600 hover:underline" @click="pick(found)">{{ t('ipPool.use') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>

        <form class="mt-4 grid gap-2 sm:grid-cols-5" @submit.prevent="submit">
            <input v-model="form.address" required :placeholder="t('ipPool.address')" class="rounded-md border-slate-300 text-sm font-mono dark:border-slate-700 dark:bg-slate-800" />
            <input v-model="form.public_address" :placeholder="t('ipPool.publicAddress')" :title="t('ipPool.publicHelp')" class="rounded-md border-slate-300 text-sm font-mono dark:border-slate-700 dark:bg-slate-800" />
            <input v-model="form.label" :placeholder="t('ipPool.label')" class="rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <select v-model="form.mode" class="rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                <option value="shared">{{ t('ipPool.shared') }}</option>
            </select>
            <button type="submit" class="rounded-md bg-sky-600 px-3 py-2 text-sm font-semibold text-white hover:bg-sky-700">{{ t('ipPool.add') }}</button>
        </form>
        <p class="mt-2 text-xs text-slate-500">{{ t('ipPool.addHelp') }}</p>
    </section>
</template>
