<script setup>
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const { t } = useI18n();

/**
 * SSL badge that opens a menu with Reinstall / Uninstall SSL once the domain already
 * has SSL. Without SSL it is the plain badge. The caller supplies the badge
 * look (badgeClass + slot) and reacts to the result through the events.
 */
const props = defineProps({
    website: { type: Object, required: true },
    badgeClass: { type: [String, Array, Object], default: '' },
    title: { type: String, default: '' },
});

const emit = defineEmits(['reinstalled', 'uninstalled', 'failed']);

const { panelRoute, requestJson } = usePanelApi();
const reinstalling = ref(false);
const uninstalling = ref(false);
const busy = () => reinstalling.value || uninstalling.value;

const reinstallSsl = async () => {
    if (busy()) return;
    if (!window.confirm(t('websites.ssl.reinstallConfirm', { domain: props.website.domain }))) return;
    reinstalling.value = true;
    try {
        const data = await requestJson(panelRoute('websites.ssl.issue', { id: props.website.id }), { body: { force: true } });
        emit('reinstalled', data);
    } catch (error) {
        emit('failed', error?.message || t('websites.ssl.reinstallFailed'));
    } finally {
        reinstalling.value = false;
    }
};

const uninstallSsl = async () => {
    if (busy()) return;
    if (!window.confirm(t('websites.ssl.uninstallConfirm', { domain: props.website.domain }))) return;
    uninstalling.value = true;
    try {
        const data = await requestJson(panelRoute('websites.ssl.uninstall', { id: props.website.id }), { method: 'DELETE' });
        emit('uninstalled', data);
    } catch (error) {
        emit('failed', error?.message || t('websites.ssl.uninstallFailed'));
    } finally {
        uninstalling.value = false;
    }
};
</script>

<template>
    <Dropdown v-if="website.enable_ssl" align="left" width="48" class="inline-flex">
        <template #trigger>
            <button type="button" :disabled="reinstalling || uninstalling" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border font-medium transition hover:brightness-95 disabled:opacity-60" :class="badgeClass" :title="title" aria-haspopup="menu" @click.prevent>
                <slot />
                <i class="bi text-[10px]" :class="reinstalling || uninstalling ? 'bi-arrow-repeat animate-spin' : 'bi-chevron-down'"></i>
            </button>
        </template>
        <template #content>
            <button type="button" :disabled="reinstalling || uninstalling" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-medium text-slate-700 transition hover:bg-slate-100 disabled:opacity-60 dark:text-slate-200 dark:hover:bg-slate-800" @click.prevent="reinstallSsl">
                <i class="bi bi-arrow-clockwise"></i>
                {{ reinstalling ? t('websites.ssl.reinstalling') : t('websites.ssl.reinstall') }}
            </button>
            <button type="button" :disabled="reinstalling || uninstalling" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-medium text-red-600 transition hover:bg-red-50 disabled:opacity-60 dark:text-red-400 dark:hover:bg-red-500/10" @click.prevent="uninstallSsl">
                <i class="bi bi-trash"></i>
                {{ uninstalling ? t('websites.ssl.uninstalling') : t('websites.ssl.uninstall') }}
            </button>
        </template>
    </Dropdown>
    <span v-else class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border font-medium" :class="badgeClass" :title="title">
        <slot />
    </span>
</template>
