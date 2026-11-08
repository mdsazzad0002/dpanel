<script setup>
import { reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({ domains: { type: Array, required: true }, busy: { type: Boolean, default: false } });
const emit = defineEmits(['submit']);
const { t } = useI18n();

const form = reactive({ local_part: '', domain: props.domains[0] || '', destinations: '' });
watch(() => props.domains, (domains) => { if (!domains.includes(form.domain)) form.domain = domains[0] || ''; });

const submit = () => {
    const destinations = form.destinations.split(/[\s,;]+/).map((v) => v.trim()).filter(Boolean);
    emit('submit', { local_part: form.local_part.trim(), domain: form.domain, destinations }, () => {
        form.local_part = '';
        form.destinations = '';
    });
};

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <form class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50" @submit.prevent="submit">
        <h2 class="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('mailForwarders.add') }}</h2>
        <div class="grid gap-3 sm:grid-cols-6">
            <label class="text-xs text-slate-500 sm:col-span-2">{{ t('mailForwarders.address') }}
                <input v-model="form.local_part" type="text" required placeholder="sales" :class="field" />
            </label>
            <label class="text-xs text-slate-500 sm:col-span-2">{{ t('mailForwarders.domain') }}
                <select v-model="form.domain" :class="field">
                    <option v-for="domain in domains" :key="domain" :value="domain">@{{ domain }}</option>
                </select>
            </label>
            <label class="text-xs text-slate-500 sm:col-span-2">{{ t('mailForwarders.destinations') }}
                <input v-model="form.destinations" type="text" required placeholder="you@example.com, team@example.com" :class="field" />
            </label>
        </div>
        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('mailForwarders.formHelp') }}</p>
            <button type="submit" :disabled="busy" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ t('mailForwarders.addButton') }}</button>
        </div>
    </form>
</template>
