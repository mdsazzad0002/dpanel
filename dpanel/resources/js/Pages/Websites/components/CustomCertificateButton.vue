<script setup>
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

/**
 * "Upload certificate": installs a certificate the customer already has
 * (backlog M5). The server checks that it is for this domain, has not
 * expired and matches the key, so the form only collects the three texts.
 */
const props = defineProps({
    website: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['installed']);

const { t } = useI18n();
const { panelRoute, requestJson } = usePanelApi();
const open = ref(false);
const saving = ref(false);
const error = ref('');
const form = reactive({ certificate: '', private_key: '', chain: '' });

const show = () => {
    Object.assign(form, { certificate: '', private_key: '', chain: '' });
    error.value = '';
    open.value = true;
};
const close = () => {
    if (saving.value) return;
    // The key does not stay in the page once the dialog is gone.
    Object.assign(form, { certificate: '', private_key: '', chain: '' });
    open.value = false;
};

// A .crt, .pem or .key file is plain text; read it into its field.
const readFile = async (event, field) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (file.size > 48 * 1024) {
        error.value = t('customCertificate.fileTooLarge');
        return;
    }
    form[field] = await file.text();
};

const submit = async () => {
    if (saving.value) return;
    saving.value = true;
    error.value = '';
    try {
        const data = await requestJson(panelRoute('websites.ssl.custom', { id: props.website.id }), { body: { ...form } });
        saving.value = false;
        close();
        emit('installed', { ...data, message: t('customCertificate.installed') });
    } catch (e) {
        error.value = e?.message || t('customCertificate.failed');
    } finally {
        saving.value = false;
    }
};

const fields = [
    { key: 'certificate', accept: '.crt,.cer,.pem', required: true, rows: 6, placeholder: '-----BEGIN CERTIFICATE-----' },
    { key: 'private_key', accept: '.key,.pem', required: true, rows: 5, placeholder: '-----BEGIN PRIVATE KEY-----' },
    { key: 'chain', accept: '.crt,.cer,.pem,.ca-bundle', required: false, rows: 4, placeholder: '-----BEGIN CERTIFICATE-----' },
];
</script>

<template>
    <button type="button" :disabled="disabled" class="rounded-md border border-blue-200 px-2.5 py-1.5 text-xs font-semibold text-blue-600 hover:border-blue-300 hover:bg-blue-50 disabled:opacity-60 dark:border-blue-800 dark:text-blue-400 dark:hover:bg-blue-500/10" @click="show">
        {{ t('customCertificate.open') }}
    </button>

    <Modal :show="open" max-width="2xl" @close="close">
        <form class="space-y-4 p-5" @submit.prevent="submit">
            <div>
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-50">{{ t('customCertificate.title', { domain: website.domain }) }}</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('customCertificate.help') }}</p>
            </div>

            <div v-for="field in fields" :key="field.key">
                <div class="mb-1 flex items-center justify-between gap-3">
                    <label :for="`custom-cert-${field.key}`" class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t(`customCertificate.fields.${field.key}`) }}</label>
                    <label class="cursor-pointer text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">
                        {{ t('customCertificate.chooseFile') }}
                        <input type="file" class="sr-only" :accept="field.accept" @change="readFile($event, field.key)" />
                    </label>
                </div>
                <textarea :id="`custom-cert-${field.key}`" v-model="form[field.key]" :rows="field.rows" :required="field.required" :placeholder="field.placeholder" spellcheck="false" autocomplete="off" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-xs text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"></textarea>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('customCertificate.note') }}</p>
            <p v-if="error" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-900/20 dark:text-red-300">{{ error }}</p>

            <div class="flex justify-end gap-2">
                <button type="button" :disabled="saving" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" :disabled="saving" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">{{ saving ? t('customCertificate.installing') : t('customCertificate.install') }}</button>
            </div>
        </form>
    </Modal>
</template>
