<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    services: { type: Array, default: () => [] },
    connection: { type: Object, default: () => ({}) },
    pgadmin: { type: Object, default: () => ({}) },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const state = ref({
    services: props.services,
    connection: props.connection,
    pgadmin: props.pgadmin,
});

const busyService = ref(null);
const refreshing = ref(false);
const message = ref(null);
const revealed = ref({});

const pgadminService = computed(() => state.value.services.find((service) => service.key === 'pgadmin'));
const postgresService = computed(() => state.value.services.find((service) => service.key === 'postgresql'));
const pgadminUrl = computed(() => state.value.pgadmin?.path || '/pgadmin4/');
// Signs in through dPanel (one-time link) as the PostgreSQL superuser.
const pgadminLoginUrl = computed(() => panelRoute('databases.postgresql.pgadmin'));
const flashError = computed(() => page.props.flash?.error || '');

const applyState = (data) => {
    if (data.services) state.value.services = data.services;
    if (data.connection) state.value.connection = data.connection;
    if (data.pgadmin) state.value.pgadmin = data.pgadmin;
};

const toggle = async (service) => {
    const turningOn = !service.active;
    if (!turningOn && !confirm(t('databases.postgresql.offConfirm', { name: service.label }))) return;
    if (turningOn && service.key === 'pgadmin' && !postgresService.value?.active
        && !confirm(t('databases.postgresql.pgOffConfirm'))) return;

    busyService.value = service.key;
    message.value = null;
    try {
        const { data } = await axios.post(panelRoute('databases.postgresql.toggle', { service: service.key }), { running: turningOn });
        applyState(data);
        message.value = { type: 'success', text: turningOn ? t('databases.postgresql.turnedOn', { name: service.label }) : t('databases.postgresql.turnedOff', { name: service.label }) };
    } catch (e) {
        if (e.response?.data) applyState(e.response.data);
        message.value = { type: 'error', text: e.response?.data?.message || t('databases.postgresql.requestFailed') };
    } finally {
        busyService.value = null;
    }
};

const refresh = async () => {
    refreshing.value = true;
    try {
        const { data } = await axios.post(panelRoute('databases.postgresql.refresh'));
        applyState(data);
    } finally {
        refreshing.value = false;
    }
};

const toggleReveal = (key) => {
    revealed.value = { ...revealed.value, [key]: !revealed.value[key] };
};

const copy = async (value) => {
    try {
        await navigator.clipboard.writeText(String(value ?? ''));
        message.value = { type: 'success', text: t('databases.postgresql.copied') };
    } catch {
        message.value = { type: 'error', text: t('databases.postgresql.copyFailed') };
    }
};

const mask = (value, key) => (revealed.value[key] ? value : '••••••••••••');
</script>

<template>
    <Head title="PostgreSQL" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">PostgreSQL</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('databases.postgresql.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="flashError && !message" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ flashError }}
            </div>
            <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="rounded-md border px-4 py-3 text-sm">
                {{ message.text }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">{{ t('databases.postgresql.services') }}</h2>
                    <button type="button" :disabled="refreshing" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="refresh">
                        {{ refreshing ? t('databases.postgresql.refreshing') : t('databases.postgresql.refreshStatus') }}
                    </button>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div v-for="service in state.services" :key="service.key" class="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-medium">{{ service.label }}</p>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><code>{{ service.unit }}</code></p>
                            </div>
                            <span
                                :class="!service.installed
                                    ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                                    : service.active
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                        : service.state === 'failed'
                                            ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'"
                                class="rounded-full px-2 py-1 text-xs"
                            >
                                {{ !service.installed ? t('databases.postgresql.notInstalled') : service.active ? t('databases.postgresql.on') : service.state === 'failed' ? t('databases.postgresql.failed') : t('databases.postgresql.off') }}
                            </span>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <button
                                v-if="service.installed"
                                type="button"
                                :disabled="busyService !== null"
                                :class="service.active
                                    ? 'border border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/20'
                                    : 'bg-blue-600 text-white hover:bg-blue-700'"
                                class="rounded-md px-3 py-2 text-sm font-medium disabled:opacity-50"
                                @click="toggle(service)"
                            >
                                {{ busyService === service.key ? t('databases.postgresql.working') : service.active ? t('databases.postgresql.turnOff') : t('databases.postgresql.turnOn') }}
                            </button>
                            <a
                                v-if="service.key === 'pgadmin' && service.active"
                                :href="pgadminLoginUrl"
                                target="_blank"
                                rel="noopener"
                                class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-200"
                            >
                                {{ t('databases.postgresql.openPgadmin') }}
                            </a>
                            <p v-if="!service.installed" class="text-xs text-slate-500 dark:text-slate-400">
                                <i18n-t keypath="databases.postgresql.installHint" scope="global"><template #cmd><code>sudo dpanel postgresql install</code></template></i18n-t>
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-6 md:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">{{ t('databases.postgresql.connection') }}</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('databases.postgresql.host') }}</dt><dd class="font-mono">{{ state.connection.host }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('databases.postgresql.port') }}</dt><dd class="font-mono">{{ state.connection.port }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ t('databases.postgresql.superuser') }}</dt><dd class="font-mono">{{ state.connection.username }}</dd></div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">{{ t('databases.postgresql.password') }}</dt>
                            <dd v-if="state.connection.password" class="flex items-center gap-2">
                                <span class="font-mono">{{ mask(state.connection.password, 'pg') }}</span>
                                <button type="button" class="text-xs text-blue-600 hover:underline dark:text-blue-300" @click="toggleReveal('pg')">{{ revealed.pg ? t('databases.postgresql.hide') : t('databases.postgresql.show') }}</button>
                                <button type="button" class="text-xs text-blue-600 hover:underline dark:text-blue-300" @click="copy(state.connection.password)">{{ t('databases.postgresql.copy') }}</button>
                            </dd>
                            <dd v-else class="text-slate-500">{{ t('databases.postgresql.notSet') }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">{{ t('databases.postgresql.localhostOnly') }}</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">{{ t('databases.postgresql.signIn') }}</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">URL</dt><dd class="font-mono">{{ pgadminUrl }}</dd></div>
                    </dl>
                    <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                        {{ t('databases.postgresql.signInHelp') }}
                        <i18n-t keypath="databases.postgresql.signInHelp2" scope="global"><template #open><strong>{{ t('databases.postgresql.openPgadmin') }}</strong></template><template #login><strong>{{ t('databases.postgresql.dbLogin') }}</strong></template></i18n-t>
                        <span v-if="pgadminService && !pgadminService.active">{{ t('databases.postgresql.turnOnHint') }}</span>
                    </p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
