<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import DockerShell from './components/DockerShell.vue';
import { useDocker } from './composables/useDocker';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const docker = useDocker();
const { status, loading, loadError, busy, message, act, post, get, panelRoute } = docker;

const system = ref(null);
const stacks = ref([]);

const load = async () => {
    await docker.load();
    if (!status.value.running) return;
    const [sys, st] = await Promise.allSettled([get('docker.system.overview'), get('docker.stacks.list')]);
    if (sys.status === 'fulfilled') system.value = sys.value.data.data;
    else loadError.value = docker.errorText(sys.reason);
    if (st.status === 'fulfilled') stacks.value = st.value.data.data.stacks || [];
};

const gb = (bytes) => (bytes ? `${(bytes / 1024 ** 3).toFixed(1)} GB` : '—');

// Containers that need a look: crash-looping, unhealthy, or stopped with an error.
const attention = computed(() => status.value.containers.filter((c) => (
    c.state === 'restarting'
    || /unhealthy/i.test(c.status)
    || (c.state === 'exited' && !/Exited \(0\)/.test(c.status))
)));

const counts = computed(() => {
    const list = status.value.containers;
    return {
        running: list.filter((c) => c.state === 'running').length,
        stopped: list.filter((c) => c.state !== 'running').length,
        stacks: stacks.value.length,
        images: status.value.images.length,
    };
});

const reclaimable = computed(() => (system.value?.disk || []).filter((d) => d.reclaimable && !/^0B/.test(d.reclaimable)));

const clean = async (action, all, text) => {
    if (!confirm(text)) return;
    const data = await act(() => post('docker.system.action', { action, all }), `${action}:${all}`, (d) => { system.value = d; });
    if (data) docker.load({ quiet: true });
};

const go = (name, query = '') => router.visit(panelRoute(name) + query);

const quick = [
    { get label() { return t('docker.overview.quick.run'); }, get hint() { return t('docker.overview.quick.runHint'); }, icon: 'bi bi-plus-square', color: 'text-indigo-600', to: () => go('docker.containers', '?run=') },
    { get label() { return t('docker.overview.quick.stack'); }, get hint() { return t('docker.overview.quick.stackHint'); }, icon: 'bi bi-stack', color: 'text-violet-600', to: () => go('docker.stacks', '?new=1') },
    { get label() { return t('docker.overview.quick.apps'); }, get hint() { return t('docker.overview.quick.appsHint'); }, icon: 'bi bi-grid-3x3-gap', color: 'text-emerald-600', to: () => go('docker.templates') },
    { get label() { return t('docker.overview.quick.pull'); }, get hint() { return t('docker.overview.quick.pullHint'); }, icon: 'bi bi-cloud-arrow-down', color: 'text-sky-600', to: () => go('docker.images') },
    { get label() { return t('docker.overview.quick.network'); }, get hint() { return t('docker.overview.quick.networkHint'); }, icon: 'bi bi-diagram-3', color: 'text-amber-600', to: () => go('docker.networks') },
    { get label() { return t('docker.overview.quick.domain'); }, get hint() { return t('docker.overview.quick.domainHint'); }, icon: 'bi bi-globe', color: 'text-rose-600', to: () => go('websites.list') },
];

onMounted(load);
</script>

<template>
    <DockerShell
        title="Docker"
        :description="t('docker.overview.description')"
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        @refresh="load"
        @dismiss="message = null"
    >
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Link :href="panelRoute('docker.containers')" class="rounded-xl border border-slate-200 bg-white p-4 hover:border-emerald-300 dark:border-slate-800 dark:bg-slate-900">
                <div class="text-xs text-slate-500">{{ t('docker.overview.running') }}</div>
                <div class="mt-1 text-2xl font-semibold text-emerald-600">{{ counts.running }}</div>
                <div class="text-xs text-slate-500">{{ t('docker.overview.stopped', { count: counts.stopped }) }}</div>
            </Link>
            <Link :href="panelRoute('docker.stacks')" class="rounded-xl border border-slate-200 bg-white p-4 hover:border-violet-300 dark:border-slate-800 dark:bg-slate-900">
                <div class="text-xs text-slate-500">{{ t('docker.nav.sections.s') }}</div>
                <div class="mt-1 text-2xl font-semibold text-violet-600">{{ counts.stacks }}</div>
                <div class="text-xs text-slate-500">{{ t('docker.overview.composeApps') }}</div>
            </Link>
            <Link :href="panelRoute('docker.images')" class="rounded-xl border border-slate-200 bg-white p-4 hover:border-sky-300 dark:border-slate-800 dark:bg-slate-900">
                <div class="text-xs text-slate-500">{{ t('docker.nav.sections.i') }}</div>
                <div class="mt-1 text-2xl font-semibold text-sky-600">{{ counts.images }}</div>
                <div class="text-xs text-slate-500">{{ t('docker.overview.onDisk', { size: system?.disk?.find((d) => d.type === 'Images')?.size || '…' }) }}</div>
            </Link>
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="text-xs text-slate-500">{{ t('docker.overview.server') }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ system?.cpus || '…' }} <span class="text-sm font-normal text-slate-500">CPU</span></div>
                <div class="text-xs text-slate-500">{{ gb(system?.memory) }} RAM</div>
            </div>
        </div>

        <section v-if="attention.length" class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40">
            <h2 class="text-sm font-semibold text-amber-900 dark:text-amber-100"><i class="bi bi-exclamation-triangle mr-1"></i>{{ t('docker.overview.attention') }}</h2>
            <ul class="mt-2 space-y-1">
                <li v-for="c in attention" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="min-w-0"><strong>{{ c.name }}</strong> <span class="text-amber-800 dark:text-amber-200">{{ c.status }}</span></span>
                    <Link :href="`${panelRoute('docker.containers')}?open=${encodeURIComponent(c.name)}`" class="text-xs font-medium text-amber-900 underline dark:text-amber-100">{{ t('docker.overview.openLogs') }}</Link>
                </li>
            </ul>
        </section>

        <section>
            <h2 class="mb-2 text-sm font-semibold text-slate-600 dark:text-slate-300">{{ t('docker.overview.quickTitle') }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <button v-for="q in quick" :key="q.label" type="button" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left hover:border-indigo-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-700" @click="q.to">
                    <i :class="[q.icon, q.color]" class="text-xl"></i>
                    <span><span class="block text-sm font-medium">{{ q.label }}</span><span class="text-xs text-slate-500">{{ q.hint }}</span></span>
                </button>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ t('docker.overview.diskUse') }}</h2>
                <table v-if="system" class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr><th class="py-1">{{ t('docker.overview.what') }}</th><th class="py-1 text-right">{{ t('docker.overview.count') }}</th><th class="py-1 text-right">{{ t('docker.overview.size') }}</th><th class="py-1 text-right">{{ t('docker.overview.canFree') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in system.disk" :key="d.type" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-1.5">{{ d.type }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ d.active }}/{{ d.total }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ d.size }}</td>
                            <td class="py-1.5 text-right tabular-nums" :class="/^0B/.test(d.reclaimable) ? 'text-slate-400' : 'text-amber-600'">{{ d.reclaimable }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mt-3 text-sm text-slate-500">{{ t('common.loading') }}</p>
                <p class="mt-3 text-xs text-slate-500"><i18n-t keypath="docker.overview.dataDir" scope="global"><template #dir><code>{{ system?.root_dir || '/var/lib/docker' }}</code></template></i18n-t></p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ t('docker.overview.cleanTitle') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ reclaimable.length ? t('docker.overview.reclaimable') : t('docker.overview.nothingToClean') }} {{ t('docker.overview.volumesKept') }}</p>
                <div class="mt-4 space-y-2">
                    <button type="button" :disabled="busy === 'prune:false'" class="flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-left text-sm hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="clean('prune', false, t('docker.overview.quickConfirm'))">
                        <span><i class="bi bi-stars mr-2 text-emerald-600"></i>{{ t('docker.overview.quickClean') }} <span class="block pl-6 text-xs text-slate-500">{{ t('docker.overview.quickCleanHint') }}</span></span>
                        <i v-if="busy === 'prune:false'" class="bi bi-arrow-repeat inline-block animate-spin"></i>
                    </button>
                    <button type="button" :disabled="busy === 'prune:true'" class="flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-left text-sm hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="clean('prune', true, t('docker.overview.deepConfirm'))">
                        <span><i class="bi bi-trash3 mr-2 text-amber-600"></i>{{ t('docker.overview.deepClean') }} <span class="block pl-6 text-xs text-slate-500">{{ t('docker.overview.deepCleanHint') }}</span></span>
                        <i v-if="busy === 'prune:true'" class="bi bi-arrow-repeat inline-block animate-spin"></i>
                    </button>
                    <button type="button" :disabled="busy === 'prune_build_cache:false'" class="flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-left text-sm hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="clean('prune_build_cache', false, t('docker.overview.cacheConfirm'))">
                        <span><i class="bi bi-hammer mr-2 text-slate-500"></i>{{ t('docker.overview.clearCache') }}</span>
                        <i v-if="busy === 'prune_build_cache:false'" class="bi bi-arrow-repeat inline-block animate-spin"></i>
                    </button>
                </div>
            </section>
        </div>

        <section v-if="system" class="rounded-xl border border-slate-200 bg-white p-5 text-sm dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-base font-semibold">{{ t('docker.overview.engine') }}</h2>
            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Docker</dt><dd>{{ system.version }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Compose</dt><dd>{{ status.compose ? t('docker.overview.installed') : t('docker.overview.notInstalled') }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">OS</dt><dd class="truncate">{{ system.os }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">{{ t('docker.overview.kernel') }}</dt><dd class="truncate">{{ system.kernel }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">{{ t('docker.overview.architecture') }}</dt><dd>{{ system.architecture }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">{{ t('docker.overview.storage') }}</dt><dd>{{ system.storage_driver }}</dd></div>
            </dl>
            <ul v-if="system.warnings?.length" class="mt-3 space-y-1 text-xs text-amber-700 dark:text-amber-300">
                <li v-for="w in system.warnings" :key="w"><i class="bi bi-exclamation-circle mr-1"></i>{{ w }}</li>
            </ul>
        </section>
    </DockerShell>
</template>
