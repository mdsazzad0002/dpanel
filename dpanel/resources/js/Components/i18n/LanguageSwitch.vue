<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { LOCALES, setLocale } from '@/i18n';

const { locale, t } = useI18n();

const options = LOCALES.map((item) => ({ value: item.code, label: item.label, keywords: item.keywords }));

const current = computed({
    get: () => locale.value,
    set: (code) => setLocale(code),
});
</script>

<template>
    <!-- Topbar control next to the notification bell, styled like its buttons. -->
    <SearchableSelect
        v-model="current"
        :options="options"
        :placeholder="t('common.language')"
        :search-placeholder="t('common.search')"
        :title="t('common.language')"
        :aria-label="t('common.language')"
        button-class="h-9 gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-500 transition-all hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-800/80"
        panel-class="right-0 w-56"
    >
        <template #prefix>
            <i class="bi bi-translate text-xs" aria-hidden="true"></i>
        </template>
    </SearchableSelect>
</template>
