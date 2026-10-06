<script setup>
import { ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MailHostnameCard from './components/MailHostnameCard.vue';
import MailIpsCard from './components/MailIpsCard.vue';

const panelToken = usePage().props.panel?.token;
const panelRoute = (name, params = {}) => panelToken ? route(name, { token: panelToken, ...params }) : route(name, params);
const mailIps = ref(null);
</script>

<template>
    <Head title="Mail SSL" />
    <AuthenticatedLayout>
        <template #header>
            <div><h1 class="text-lg font-semibold">Mail SSL</h1><p class="text-sm text-slate-500 dark:text-slate-400">Mail IPs, their hostnames, and the certificates Postfix and Dovecot serve.</p></div>
        </template>

        <div class="space-y-5">
            <MailIpsCard ref="mailIps" :panel-route="panelRoute" />
            <MailHostnameCard :panel-route="panelRoute" @changed="mailIps?.load()" />
        </div>
    </AuthenticatedLayout>
</template>
