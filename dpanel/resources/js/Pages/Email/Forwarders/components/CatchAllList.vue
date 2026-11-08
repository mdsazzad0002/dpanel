<script setup>
import { reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    domains: { type: Array, required: true },
    catchAlls: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['save']);
const { t } = useI18n();

const targets = reactive({});
watch(() => [props.domains, props.catchAlls], () => {
    props.domains.forEach((domain) => { targets[domain] = props.catchAlls[domain] || ''; });
}, { immediate: true, deep: true });

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('mailForwarders.catchAll') }}</h2>
        <p class="mb-3 mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('mailForwarders.catchAllHelp') }}</p>
        <div class="space-y-2">
            <form v-for="domain in domains" :key="domain" class="flex flex-col gap-2 sm:flex-row sm:items-center" @submit.prevent="emit('save', domain, targets[domain].trim())">
                <span class="w-full truncate text-sm font-medium text-slate-700 dark:text-slate-200 sm:w-56">@{{ domain }}</span>
                <input v-model="targets[domain]" type="email" :placeholder="t('mailForwarders.catchAllOff')" :class="field" />
                <button type="submit" :disabled="busy" class="shrink-0 rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900 disabled:opacity-50 dark:bg-slate-700">{{ t('mailForwarders.save') }}</button>
            </form>
        </div>
    </div>
</template>
