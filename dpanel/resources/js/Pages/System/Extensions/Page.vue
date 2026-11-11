<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/composables/usePanelApi';
import ExtensionFrame from './components/ExtensionFrame.vue';

// An extension's admin page, framed from its own origin (backlog U7).
defineProps({
    extension: { type: Object, required: true },
    frame: { type: Object, required: true },
});
const { t } = useI18n();
const { panelRoute } = usePanelApi(t);
</script>

<template>
    <Head :title="extension.ui_title || extension.name" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-3">
                <div class="min-w-0 flex-1">
                    <h1 class="text-lg font-semibold">{{ extension.ui_title || extension.name }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('extensions.frameNote', { name: extension.name, origin: frame.origin }) }}</p>
                </div>
                <a :href="panelRoute('system.extensions.index')" class="text-xs font-semibold text-blue-600">{{ t('extensions.back') }}</a>
            </div>
        </template>

        <ExtensionFrame :frame="frame" :title="extension.name" />
    </AuthenticatedLayout>
</template>
