<script setup>
import axios from 'axios';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

defineProps({
    panelUrl: { type: String, default: '' },
});
const emit = defineEmits(['created']);
const { t } = useI18n();

const open = ref(false);
const busy = ref(false);
const error = ref('');
const form = reactive({ name: '', base_url: '', token: '' });

const submit = async () => {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(route('migrations.dpanel.link.connections.store'), form);
        emit('created', data.connection);
        Object.assign(form, { name: '', base_url: '', token: '' });
        open.value = false;
    } catch (e) {
        error.value = e.response?.data?.message || t('common.sessionExpired');
    } finally {
        busy.value = false;
    }
};

const copy = (text) => navigator.clipboard?.writeText(text);
</script>

<template>
    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
        <button v-if="!open" type="button" class="flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" @click="open = true">
            <i class="bi bi-plus-lg"></i>{{ t('panelLink.addConnection') }}
        </button>

        <form v-else class="space-y-3" @submit.prevent="submit">
            <ol class="list-decimal space-y-1 pl-4 text-xs text-slate-500 dark:text-slate-400">
                <li>{{ t('panelLink.step1') }}</li>
                <li>{{ t('panelLink.step2') }}</li>
                <li>{{ t('panelLink.step3') }}</li>
            </ol>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">
                {{ t('panelLink.otherUrl') }}
                <input v-model.trim="form.base_url" required placeholder="https://panel.example.com" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">
                {{ t('panelLink.token') }}
                <input v-model.trim="form.token" required type="password" placeholder="dp-api-…" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">
                {{ t('panelLink.name') }} <span class="font-normal text-slate-400">({{ t('panelLink.optional') }})</span>
                <input v-model.trim="form.name" maxlength="120" :placeholder="t('panelLink.namePlaceholder')" class="mt-1 block w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ error }}</p>
            <div class="flex gap-2">
                <button :disabled="busy" class="flex-1 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{{ busy ? t('panelLink.connecting') : t('panelLink.connect') }}</button>
                <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="open = false">{{ t('common.cancel') }}</button>
            </div>
        </form>

        <div class="mt-4 border-t border-slate-200 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
            <p>{{ t('panelLink.thisPanelUrl') }}</p>
            <div class="mt-1 flex items-center gap-2">
                <code class="min-w-0 flex-1 truncate rounded bg-slate-100 px-2 py-1 text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ panelUrl }}</code>
                <button type="button" class="rounded p-1 hover:bg-slate-100 dark:hover:bg-slate-800" :title="t('panelLink.copy')" @click="copy(panelUrl)"><i class="bi bi-clipboard"></i></button>
            </div>
            <a :href="route('settings.api-tokens')" class="mt-2 inline-flex items-center gap-1 font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                <i class="bi bi-key"></i>{{ t('panelLink.makeTokenHere') }}
            </a>
        </div>
    </div>
</template>
