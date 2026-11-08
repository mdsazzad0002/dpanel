<script setup>
import { useI18n } from 'vue-i18n';
import { LOCALES, setLocale } from '@/i18n';

const { locale, t } = useI18n();
</script>

<template>
    <label class="inline-flex items-center gap-1 text-xs text-slate-400 dark:text-slate-500">
        <i class="bi bi-translate" aria-hidden="true"></i>
        <span class="sr-only">{{ t('common.language') }}</span>
        <select
            :value="locale"
            class="rounded-md border-0 bg-transparent py-0.5 pl-1 pr-6 text-xs text-slate-500 focus:ring-1 focus:ring-blue-500 dark:text-slate-400"
            :aria-label="t('common.language')"
            @change="setLocale($event.target.value)"
        >
            <option v-for="item in LOCALES" :key="item.code" :value="item.code">{{ item.label }}</option>
        </select>
    </label>
</template>
