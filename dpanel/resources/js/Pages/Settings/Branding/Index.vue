<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/composables/usePanelApi';

const props = defineProps({
    branding: { type: Object, required: true },
    scope: { type: String, default: 'server' },
});
const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);

const form = reactive({ panel_name: props.branding.panel_name || '', primary_color: props.branding.primary_color || '' });
const logo = ref(null);
const preview = ref(props.branding.logo_url || '');
const busy = ref(false);
const error = ref('');
const notice = ref('');

const pick = (event) => {
    logo.value = event.target.files?.[0] || null;
    if (logo.value) preview.value = URL.createObjectURL(logo.value);
};

const save = async (removeLogo = false) => {
    busy.value = true;
    error.value = '';
    notice.value = '';
    const body = new FormData();
    body.append('panel_name', form.panel_name.trim());
    if (form.primary_color) body.append('primary_color', form.primary_color);
    if (logo.value && !removeLogo) body.append('logo', logo.value);
    if (removeLogo) body.append('remove_logo', '1');
    try {
        const { data } = await window.axios.post(panelRoute('settings.branding.update'), body);
        notice.value = data.message;
        if (removeLogo) preview.value = '';
        logo.value = null;
        router.reload();
    } catch (e) {
        error.value = errorMessage(e, t('branding.failed'));
    } finally {
        busy.value = false;
    }
};

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <Head :title="t('branding.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('branding.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ scope === 'server' ? t('branding.serverHelp') : t('branding.resellerHelp') }}</p>
            </div>
        </template>

        <form class="max-w-2xl space-y-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50" @submit.prevent="save(false)">
            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
            <div v-if="notice" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</div>

            <label class="block text-xs text-slate-500">{{ t('branding.name') }}
                <input v-model="form.panel_name" type="text" maxlength="60" placeholder="dPanel" :class="field" />
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-xs text-slate-500">{{ t('branding.color') }}
                    <div class="flex items-center gap-2">
                        <input v-model="form.primary_color" type="color" class="h-10 w-14 cursor-pointer rounded-lg border border-slate-200 dark:border-slate-700" />
                        <input v-model="form.primary_color" type="text" placeholder="#2563eb" pattern="#[0-9a-fA-F]{6}" :class="field" />
                    </div>
                </label>
                <div class="text-xs text-slate-500">{{ t('branding.logo') }}
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-sm" @change="pick" />
                    <p class="mt-1">{{ t('branding.logoHelp') }}</p>
                </div>
            </div>

            <div v-if="preview" class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                <img :src="preview" :alt="t('branding.logo')" class="h-10 max-w-[200px] object-contain" />
                <button type="button" :disabled="busy" class="ml-auto text-xs font-semibold text-red-600 disabled:opacity-50" @click="save(true)">{{ t('branding.removeLogo') }}</button>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('branding.fallback') }}</p>
            <div class="flex justify-end">
                <button type="submit" :disabled="busy" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ t('branding.save') }}</button>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
