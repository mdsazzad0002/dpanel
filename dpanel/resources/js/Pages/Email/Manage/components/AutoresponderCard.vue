<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/composables/usePanelApi';

const props = defineProps({ mailboxId: { type: String, required: true } });
const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);

const supported = ref(false);
const loaded = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const form = reactive({ enabled: false, subject: '', body: '', interval_days: 1, starts_on: '', ends_on: '' });

onMounted(async () => {
    try {
        const { data } = await window.axios.get(panelRoute('emails.autoresponder', { id: props.mailboxId }));
        supported.value = !!data.supported;
        Object.assign(form, data.autoresponder || {});
        form.starts_on = form.starts_on || '';
        form.ends_on = form.ends_on || '';
    } catch (e) {
        error.value = errorMessage(e, t('autoresponder.loadFailed'));
    } finally {
        loaded.value = true;
    }
});

const save = async () => {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await window.axios.put(panelRoute('emails.autoresponder.update', { id: props.mailboxId }), {
            ...form, starts_on: form.starts_on || null, ends_on: form.ends_on || null,
        });
        notice.value = data.message;
    } catch (e) {
        error.value = errorMessage(e, t('autoresponder.saveFailed'));
    } finally {
        busy.value = false;
    }
};

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <form v-if="loaded && supported" class="mt-6 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50" @submit.prevent="save">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('autoresponder.title') }}</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('autoresponder.help') }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                <input v-model="form.enabled" type="checkbox" class="rounded" /> {{ t('autoresponder.on') }}
            </label>
        </div>
        <div v-if="error" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
        <div v-if="notice" class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</div>
        <div v-if="form.enabled" class="mt-3 grid gap-3 sm:grid-cols-3">
            <label class="text-xs text-slate-500 sm:col-span-3">{{ t('autoresponder.subject') }}
                <input v-model="form.subject" type="text" maxlength="200" required :class="field" />
            </label>
            <label class="text-xs text-slate-500 sm:col-span-3">{{ t('autoresponder.body') }}
                <textarea v-model="form.body" rows="5" maxlength="10000" required :class="field"></textarea>
            </label>
            <label class="text-xs text-slate-500">{{ t('autoresponder.from') }}
                <input v-model="form.starts_on" type="date" :class="field" />
            </label>
            <label class="text-xs text-slate-500">{{ t('autoresponder.until') }}
                <input v-model="form.ends_on" type="date" :class="field" />
            </label>
            <label class="text-xs text-slate-500">{{ t('autoresponder.interval') }}
                <input v-model.number="form.interval_days" type="number" min="1" max="30" :class="field" />
            </label>
        </div>
        <div class="mt-3 flex justify-end">
            <button type="submit" :disabled="busy" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ t('autoresponder.save') }}</button>
        </div>
    </form>
</template>
