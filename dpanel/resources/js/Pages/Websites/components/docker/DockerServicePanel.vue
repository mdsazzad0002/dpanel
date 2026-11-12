<script setup>
import { useI18n } from 'vue-i18n';
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const { t } = useI18n();

// The container behind a Docker website: where it answers, whether its port
// is open to the internet, start/stop, logs, and where its files live.
const props = defineProps({
    website: { type: Object, required: true },
    // { installed, env, server_ip } from the Manage page; admins only.
    docker: { type: Object, required: true },
});

const pushToast = inject('pushToast');
const { panelRoute, requestJson } = usePanelApi();

const loading = ref('');
const container = ref(null);
const logs = ref('');
const showLogs = ref(false);

const status = computed(() => String(props.website?.docker_process_status || 'pending'));
const statusLabel = computed(() => ({ running: t('websites.dockerService.running'), stopped: t('websites.dockerService.stopped'), error: t('websites.dockerService.error') })[status.value] || t('websites.dockerService.starting'));
const statusClass = computed(() => ({
    running: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400',
    stopped: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
    error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400',
})[status.value] || 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400');

const scheme = computed(() => (props.website?.enable_ssl ? 'https' : 'http'));
const domainUrl = computed(() => `${scheme.value}://${props.website?.domain || ''}`);
// Behind NAT the panel cannot see its public IP; the address it was opened on is the next best guess.
const host = computed(() => props.docker?.server_ip || window.location.hostname);
const publicUrl = computed(() => `http://${host.value}:${props.website?.docker_port || ''}`);
const fileManagerUrl = computed(() => panelRoute('websites.filemanager', { id: props.website.id }));
// The site fronts a port a stack (or anything else) already serves, instead of running its own container.
const fronting = computed(() => props.website?.docker_source === 'port');
const stackName = computed(() => String(props.website?.docker_stack || ''));
const stackUrl = computed(() => `${panelRoute('docker.stacks')}?open=${encodeURIComponent(stackName.value)}`);

// Pulling an image runs in the background; keep the status fresh until it settles.
let poll = null;
const stopPolling = () => {
    if (poll) window.clearInterval(poll);
    poll = null;
};
watch(status, (value) => {
    stopPolling();
    if (value === 'pending') {
        poll = window.setInterval(() => router.reload({ only: ['website'], preserveScroll: true }), 5000);
    }
}, { immediate: true });
onBeforeUnmount(stopPolling);

const control = async (action) => {
    if (loading.value) return;
    if (action === 'stop' && !window.confirm(fronting.value ? t('websites.dockerService.stopStackConfirm', { stack: stackName.value }) : t('websites.dockerService.stopConfirm'))) return;
    if (action === 'recreate' && !window.confirm(fronting.value ? t('websites.dockerService.redeployConfirm', { stack: stackName.value }) : t('websites.dockerService.recreateConfirm'))) return;
    loading.value = action;
    try {
        const data = await requestJson(panelRoute('websites.docker.control', { id: props.website.id }), { body: { action } });
        if (action === 'logs') {
            logs.value = data.data?.logs || '(no output yet)';
            showLogs.value = true;
            return;
        }
        container.value = data.data?.container || null;
        if (action !== 'status') {
            pushToast?.(data.message || t('websites.dockerService.updated'), 'success');
            router.reload({ only: ['website'], preserveScroll: true });
        }
    } catch (error) {
        pushToast?.(error?.message || t('websites.dockerService.failed'), 'error');
        if (action !== 'status' && action !== 'logs') router.reload({ only: ['website'], preserveScroll: true });
    } finally {
        loading.value = '';
    }
};

const togglePublic = async () => {
    if (loading.value) return;
    const next = !props.website?.docker_public;
    if (next && !window.confirm(t('websites.dockerService.publicConfirm', { port: props.website?.docker_port, url: publicUrl.value }))) return;
    loading.value = 'public';
    try {
        const data = await requestJson(panelRoute('websites.docker.public', { id: props.website.id }), { method: 'PATCH', body: { public: next } });
        pushToast?.(data.message, 'success');
        router.reload({ only: ['website'], preserveScroll: true });
    } catch (error) {
        pushToast?.(error?.message || t('websites.dockerService.portFailed'), 'error');
    } finally {
        loading.value = '';
    }
};

const button = 'inline-flex items-center gap-2 rounded-lg border px-3.5 py-2 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-60';
const neutral = `${button} border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300`;
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <i class="bi bi-box-seam text-base text-sky-600 dark:text-sky-400"></i>
                    {{ t('websites.dockerService.title') }}
                </h3>
                <p v-if="fronting" class="mt-1 break-all text-xs text-slate-500 dark:text-slate-400">
                    {{ stackName ? t('websites.dockerService.stackName', { stack: stackName }) : t('websites.dockerService.appOnServer') }} · {{ t('websites.dockerService.serverPort', { port: website.docker_port || '-' }) }}
                </p>
                <p v-else class="mt-1 break-all text-xs text-slate-500 dark:text-slate-400">
                    {{ website.docker_image || t('websites.dockerService.noImage') }}
                    · {{ t('websites.dockerService.appPort', { port: website.docker_container_port || '-' }) }}
                    · {{ t('websites.dockerService.serverPort', { port: website.docker_port || '-' }) }}
                </p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium" :class="statusClass">
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ statusLabel }}
            </span>
        </div>

        <p v-if="!docker.installed" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            <i18n-t keypath="websites.dockerService.notInstalled"><template #cmd><code>sudo dpanel install docker</code></template></i18n-t>
        </p>
        <p v-else-if="status === 'error'" class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
            {{ fronting ? t('websites.dockerService.stackFailed') : t('websites.dockerService.containerFailed') }}
        </p>

        <div v-if="fronting" class="mt-4 grid gap-3 md:grid-cols-2">
            <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                <div class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ t('websites.dockerService.openApp') }}</div>
                <a :href="domainUrl" target="_blank" rel="noopener" class="mt-1 block break-all text-sm text-blue-600 hover:underline dark:text-blue-400">{{ domainUrl }}</a>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('websites.dockerService.domainSent', { port: website.docker_port }) }}</p>
            </div>
            <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                <div class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ t('websites.dockerService.behind') }}</div>
                <template v-if="stackName">
                    <i18n-t keypath="websites.dockerService.stackLabel" tag="p" class="mt-1 text-sm"><template #name><strong>{{ stackName }}</strong></template></i18n-t>
                    <a :href="stackUrl" class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"><i class="bi bi-stack"></i> {{ t('websites.dockerService.openStack') }}</a>
                </template>
                <p v-else class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('websites.dockerService.noStack') }}</p>
            </div>
        </div>

        <div v-else class="mt-4 grid gap-3 md:grid-cols-2">
            <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                <div class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ t('websites.dockerService.openApp') }}</div>
                <a :href="domainUrl" target="_blank" rel="noopener" class="mt-1 block break-all text-sm text-blue-600 hover:underline dark:text-blue-400">{{ domainUrl }}</a>
                <a v-if="website.docker_public" :href="publicUrl" target="_blank" rel="noopener" class="mt-1 block break-all text-sm text-blue-600 hover:underline dark:text-blue-400">{{ publicUrl }}</a>
                <label class="mt-3 flex items-start gap-2 text-xs text-slate-700 dark:text-slate-300">
                    <input type="checkbox" class="mt-0.5" :checked="Boolean(website.docker_public)" :disabled="Boolean(loading)" @change.prevent="togglePublic" />
                    <span>
                        {{ t('websites.dockerService.openPort', { port: website.docker_port }) }}
                        <span class="block text-slate-500 dark:text-slate-400">{{ t('websites.dockerService.openPortHelp') }}</span>
                    </span>
                </label>
            </div>
            <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                <div class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ t('websites.dockerService.files') }}</div>
                <template v-if="website.docker_mount_target">
                    <p class="mt-1 break-all font-mono text-xs text-slate-700 dark:text-slate-300">{{ website.root_path }}</p>
                    <p class="break-all font-mono text-xs text-slate-500 dark:text-slate-400">→ {{ t('websites.dockerService.inContainer', { path: website.docker_mount_target }) }}</p>
                    <a :href="fileManagerUrl" class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">
                        <i class="bi bi-folder2-open"></i> {{ t('websites.dockerService.editFiles') }}
                    </a>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('websites.dockerService.restartAfter') }}</p>
                </template>
                <p v-else class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ t('websites.dockerService.noMount') }}
                </p>
            </div>
        </div>

        <div v-if="container" class="mt-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
            docker: {{ container.state || t('websites.dockerService.unknown') }} · {{ container.status || '' }}
        </div>

        <div v-if="!fronting || stackName" class="mt-4 flex flex-wrap gap-2">
            <button type="button" :disabled="Boolean(loading)" :class="button" class="border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400" @click="control('start')">
                <i class="bi bi-play-fill"></i> {{ loading === 'start' ? t('websites.dockerService.starting') : t('websites.dockerService.start') }}
            </button>
            <button type="button" :disabled="Boolean(loading)" :class="button" class="border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400" @click="control('restart')">
                <i class="bi bi-arrow-clockwise"></i> {{ loading === 'restart' ? t('websites.dockerService.restarting') : t('websites.dockerService.restart') }}
            </button>
            <button type="button" :disabled="Boolean(loading)" :class="button" class="border-red-200 bg-red-50 text-red-700 hover:border-red-300 hover:bg-red-100 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400" @click="control('stop')">
                <i class="bi bi-stop-fill"></i> {{ loading === 'stop' ? t('websites.dockerService.stopping') : t('websites.dockerService.stop') }}
            </button>
            <button type="button" :disabled="Boolean(loading)" :class="neutral" @click="control('recreate')">
                <i class="bi bi-arrow-repeat"></i> {{ loading === 'recreate' ? (fronting ? t('websites.dockerService.deploying') : t('websites.dockerService.recreating')) : (fronting ? t('websites.dockerService.redeploy') : t('websites.dockerService.recreate')) }}
            </button>
            <button type="button" :disabled="Boolean(loading)" :class="neutral" @click="control('logs')">
                <i class="bi bi-journal-text"></i> {{ loading === 'logs' ? t('common.loading') : t('websites.dockerService.logs') }}
            </button>
            <button type="button" :disabled="Boolean(loading)" :class="neutral" @click="control('status')">
                <i class="bi bi-activity"></i> {{ loading === 'status' ? t('websites.dockerService.checking') : t('websites.dockerService.refreshStatus') }}
            </button>
        </div>

        <div v-if="showLogs" class="mt-4">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ t('websites.dockerService.lastLines') }}</span>
                <button type="button" class="text-xs text-slate-500 hover:text-slate-700 dark:hover:text-slate-300" @click="showLogs = false">{{ t('websites.dockerService.hide') }}</button>
            </div>
            <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-950 p-3 text-[11px] leading-relaxed text-slate-100">{{ logs }}</pre>
        </div>

        <ol v-if="!fronting" class="mt-4 list-decimal space-y-1 pl-5 text-xs text-slate-500 dark:text-slate-400">
            <li>{{ t('websites.dockerService.step1') }}</li>
            <li>{{ t('websites.dockerService.step2') }}</li>
            <li>{{ t('websites.dockerService.step3') }}</li>
        </ol>
    </section>
</template>
