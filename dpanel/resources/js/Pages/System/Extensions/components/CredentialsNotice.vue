<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

// The extension's token and webhook secret, shown once (U7).
defineProps({
    credentials: { type: Object, required: true },
    apiBase: { type: String, default: '' },
});
const emit = defineEmits(['close']);
const { t } = useI18n();
const copied = ref('');

const copy = async (key, value) => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
    } catch (e) {
        copied.value = '';
    }
};
</script>

<template>
    <div class="space-y-2 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-800 dark:bg-amber-950/40">
        <p class="font-semibold text-amber-800 dark:text-amber-200">{{ t('extensions.shownOnce', { name: credentials.name }) }}</p>
        <div v-for="[key, value] in [['token', credentials.token], ['secret', credentials.secret]]" :key="key" class="flex flex-wrap items-center gap-2">
            <span class="w-28 text-xs text-slate-600 dark:text-slate-300">{{ t(`extensions.${key}`) }}</span>
            <code class="min-w-0 flex-1 break-all rounded-md bg-white px-2 py-1 text-xs dark:bg-slate-900">{{ value }}</code>
            <button type="button" class="rounded-lg border border-amber-300 px-2 py-1 text-xs font-semibold dark:border-amber-800" @click="copy(key, value)">{{ copied === key ? t('extensions.copied') : t('extensions.copy') }}</button>
        </div>
        <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('extensions.credentialsHelp', { api: apiBase }) }}</p>
        <button type="button" class="text-xs font-semibold text-amber-800 dark:text-amber-200" @click="emit('close')">{{ t('extensions.savedThem') }}</button>
    </div>
</template>
