<script setup>
import { reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/composables/usePanelApi';

const props = defineProps({ relay: { type: Object, default: () => ({ host: '', port: null, username: '' }) } });
const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);

const current = ref({ ...props.relay });
const form = reactive({ host: props.relay.host || '', port: props.relay.port || 587, username: props.relay.username || '', password: '' });
const busy = ref(false);
const error = ref('');
const notice = ref('');

const run = async (request) => {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await request();
        notice.value = data.message;
        current.value = data.relay || { host: '', port: null, username: '' };
        form.password = '';
    } catch (e) {
        error.value = errorMessage(e, t('smtpRelay.failed'));
    } finally {
        busy.value = false;
    }
};

const save = () => run(() => window.axios.put(panelRoute('emails.smtp-relay.update'), {
    host: form.host.trim(), port: Number(form.port), username: form.username.trim() || null, password: form.password || null,
}));
const disable = () => {
    if (window.confirm(t('smtpRelay.disableConfirm'))) run(() => window.axios.delete(panelRoute('emails.smtp-relay.destroy')));
};

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <Head :title="t('smtpRelay.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('smtpRelay.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('smtpRelay.subtitle') }}</p>
            </div>
        </template>

        <div class="max-w-3xl space-y-5">
            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"><i class="bi bi-exclamation-triangle-fill mr-2"></i>{{ error }}</div>
            <div v-if="notice" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300"><i class="bi bi-check-circle-fill mr-2"></i>{{ notice }}</div>

            <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ t('smtpRelay.now') }}</p>
                <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-100">
                    {{ current.host ? t('smtpRelay.through', { host: current.host, port: current.port || '' }) : t('smtpRelay.direct') }}
                    <span v-if="current.username" class="font-normal text-slate-500"> · {{ current.username }}</span>
                </p>
            </div>

            <form class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50" @submit.prevent="save">
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-xs text-slate-500 sm:col-span-3">{{ t('smtpRelay.host') }}
                        <input v-model="form.host" type="text" required placeholder="smtp.example.com" :class="field" />
                    </label>
                    <label class="text-xs text-slate-500">{{ t('smtpRelay.port') }}
                        <select v-model.number="form.port" :class="field">
                            <option :value="587">587 (STARTTLS)</option>
                            <option :value="465">465 (TLS)</option>
                            <option :value="2525">2525</option>
                            <option :value="25">25</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500 sm:col-span-2">{{ t('smtpRelay.username') }}
                        <input v-model="form.username" type="text" autocomplete="off" :class="field" />
                    </label>
                    <label class="text-xs text-slate-500 sm:col-span-2">{{ t('smtpRelay.password') }}
                        <input v-model="form.password" type="password" autocomplete="new-password" :class="field" />
                    </label>
                </div>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ t('smtpRelay.help') }}</p>
                <div class="mt-3 flex flex-wrap justify-end gap-2">
                    <button v-if="current.host" type="button" :disabled="busy" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200" @click="disable">{{ t('smtpRelay.disable') }}</button>
                    <button type="submit" :disabled="busy" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ t('smtpRelay.save') }}</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
