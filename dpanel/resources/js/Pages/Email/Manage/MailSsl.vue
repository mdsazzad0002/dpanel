<script setup>
import { ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MailHostnameCard from './components/MailHostnameCard.vue';
import MailIpsCard from './components/MailIpsCard.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const panelToken = usePage().props.panel?.token;
const panelRoute = (name, params = {}) => panelToken ? route(name, { token: panelToken, ...params }) : route(name, params);
const mailIps = ref(null);
</script>

<template>
    <Head :title="t('email.mailSsl.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div><h1 class="text-lg font-semibold">{{ t('email.mailSsl.title') }}</h1><p class="text-sm text-slate-500 dark:text-slate-400">{{ t('email.mailSsl.subtitle') }}</p></div>
        </template>

        <div class="space-y-5">
            <MailIpsCard ref="mailIps" :panel-route="panelRoute" />
            <MailHostnameCard :panel-route="panelRoute" @changed="mailIps?.load()" />
        </div>
    </AuthenticatedLayout>
</template>
