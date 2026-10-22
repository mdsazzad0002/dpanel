<script setup>
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

/**
 * SSL badge that opens a menu with "Reinstall SSL" once the domain already
 * has SSL. Without SSL it is the plain badge. The caller supplies the badge
 * look (badgeClass + slot) and reacts to the result through the events.
 */
const props = defineProps({
    website: { type: Object, required: true },
    badgeClass: { type: [String, Array, Object], default: '' },
    title: { type: String, default: '' },
});

const emit = defineEmits(['reinstalled', 'failed']);

const { panelRoute, requestJson } = usePanelApi();
const reinstalling = ref(false);

const reinstallSsl = async () => {
    if (reinstalling.value) return;
    if (!window.confirm(`Reinstall the SSL certificate for ${props.website.domain}? A new certificate is issued even if the current one is still valid.`)) return;
    reinstalling.value = true;
    try {
        const data = await requestJson(panelRoute('websites.ssl.issue', { id: props.website.id }), { body: { force: true } });
        emit('reinstalled', data);
    } catch (error) {
        emit('failed', error?.message || 'SSL reinstall failed.');
    } finally {
        reinstalling.value = false;
    }
};
</script>

<template>
    <Dropdown v-if="website.enable_ssl" align="left" width="48" class="inline-flex">
        <template #trigger>
            <button type="button" :disabled="reinstalling" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border font-medium transition hover:brightness-95 disabled:opacity-60" :class="badgeClass" :title="title" aria-haspopup="menu" @click.prevent>
                <slot />
                <i class="bi text-[10px]" :class="reinstalling ? 'bi-arrow-repeat animate-spin' : 'bi-chevron-down'"></i>
            </button>
        </template>
        <template #content>
            <button type="button" :disabled="reinstalling" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-medium text-slate-700 transition hover:bg-slate-100 disabled:opacity-60 dark:text-slate-200 dark:hover:bg-slate-800" @click.prevent="reinstallSsl">
                <i class="bi bi-arrow-clockwise"></i>
                {{ reinstalling ? 'Reinstalling...' : 'Reinstall SSL' }}
            </button>
        </template>
    </Dropdown>
    <span v-else class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border font-medium" :class="badgeClass" :title="title">
        <slot />
    </span>
</template>
