<script setup>
import { useI18n } from 'vue-i18n';
import Offcanvas from '@/Components/Offcanvas.vue';
import QuickActionsPanel from '@/Pages/Websites/components/QuickActionsPanel.vue';
import DevelopmentSettingsPanel from '@/Pages/Websites/components/DevelopmentSettingsPanel.vue';

const { t } = useI18n();

const props = defineProps({
    show: { type: Boolean, default: false },
    website: { type: Object, required: true },
    rootInspection: { type: Object, default: () => ({}) },
    databaseConnection: { type: Object, default: () => ({ available: false }) },
    connectedDatabase: { type: String, default: null },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Offcanvas :show="show" width="lg" :title="t('fileManager.settings.title')"
        :subtitle="props.website.domain" @close="emit('close')">
        <QuickActionsPanel
            embedded
            :website="props.website"
            :root-inspection="props.rootInspection"
            :database-connection="props.databaseConnection"
            :connected-database="props.connectedDatabase"
        />

        <div class="mt-5 border-t border-slate-200 pt-5 dark:border-slate-800">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ t('fileManager.settings.development') }}</p>
            <div class="mt-3">
                <DevelopmentSettingsPanel :website="props.website" :root-inspection="props.rootInspection" />
            </div>
        </div>
    </Offcanvas>
</template>
