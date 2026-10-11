<script setup>
import { computed } from 'vue';

const props = defineProps({
    services: { type: Object, default: null },
});

const OK = ['running', 'active', 'online', 'mariadb', 'mysql', 'sqlite'];
const DOWN = ['down', 'stopped', 'inactive', 'offline', 'failed'];

const statusTone = (status) => {
    const s = String(status).toLowerCase();
    if (OK.includes(s)) return { dot: 'bg-emerald-500', text: 'text-emerald-700 dark:text-emerald-400', ping: true };
    if (DOWN.includes(s)) return { dot: 'bg-red-500', text: 'text-red-600 dark:text-red-400', ping: false };
    return { dot: 'bg-amber-500', text: 'text-amber-600 dark:text-amber-400', ping: false };
};

const list = computed(() => {
    const svc = props.services ?? {};
    return [
        { name: 'Drust Gateway', status: svc.drust_gateway, icon: 'bi-lightning-charge' },
        { name: 'Drust API', status: svc.drust_api, icon: 'bi-cpu' },
        { name: 'MySQL', status: svc.database, icon: 'bi-database' },
        { name: 'Redis', status: svc.redis, icon: 'bi-memory' },
        { name: 'Postfix', status: svc.mail, icon: 'bi-envelope' },
        { name: 'Dovecot', status: svc.dovecot, icon: 'bi-inbox' },
    ].filter((s) => s.status).map((s) => ({ ...s, tone: statusTone(s.status) }));
});

const healthy = computed(() => list.value.filter((s) => s.tone.ping).length);
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
        <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5 dark:border-slate-700">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Services</h2>
            <span v-if="list.length" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                {{ healthy }}/{{ list.length }} running
            </span>
        </header>

        <ul v-if="services" class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="service in list" :key="service.name" class="flex items-center gap-3 px-5 py-3">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <i :class="['bi', service.icon]"></i>
                </span>
                <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ service.name }}</span>
                <span :class="['flex items-center gap-1.5 text-xs font-medium capitalize', service.tone.text]">
                    <span class="relative flex h-2 w-2">
                        <span v-if="service.tone.ping" :class="['absolute inline-flex h-full w-full animate-ping rounded-full opacity-60', service.tone.dot]"></span>
                        <span :class="['relative inline-flex h-2 w-2 rounded-full', service.tone.dot]"></span>
                    </span>
                    {{ service.status }}
                </span>
            </li>
            <li v-if="!list.length" class="px-5 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No service data available.</li>
        </ul>
        <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="n in 4" :key="n" class="flex items-center gap-3 px-5 py-3">
                <span class="h-8 w-8 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800"></span>
                <span class="h-3.5 flex-1 animate-pulse rounded bg-slate-100 dark:bg-slate-800"></span>
            </li>
        </ul>
    </section>
</template>
