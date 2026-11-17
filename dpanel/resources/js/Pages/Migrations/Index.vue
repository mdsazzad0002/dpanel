<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CyberPanelSshMigration from './components/CyberPanelSshMigration.vue';
import PanelLink from './components/PanelLink/PanelLink.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, te } = useI18n();

const props = defineProps({
    provider: { type: String, default: null },
    imports: { type: Array, default: () => [] },
    canDpanelMigration: { type: Boolean, default: false },
    savedSshConnections: { type: Array, default: () => [] },
    phpVersions: { type: Array, default: () => [] },
    websites: { type: Array, default: () => [] },
});
const archive = ref(null);
// cPanel partial: several files (home directory + MySQL backups) and the
// account they belong to, which those files do not record.
const partialFiles = ref([]);
const partial = reactive({ account: '', domain: '' });
const uploading = ref(false);
const restoring = ref(null);
const message = ref('');
const error = ref('');
const choices = reactive({});
const confirmation = ref('');
const services = computed(() => [
    { id: 'cyberpanel-ssh', name: t('migrations.index.services.cyberpanelSsh.name'), description: t('migrations.index.services.cyberpanelSsh.description'), icon: 'bi bi-terminal', available: true, formats: 'SSH password or private key' },
    { id: 'cpanel-full', name: t('migrations.index.services.cpanelFull.name'), description: t('migrations.index.services.cpanelFull.description'), icon: 'bi bi-server', available: true, formats: 'cpmove-USER.tar.gz, backup-…_USER.tar.gz' },
    { id: 'dpanel-full', name: t('migrations.index.services.dpanelFull.name'), description: t('migrations.index.services.dpanelFull.description'), icon: 'bi bi-arrow-left-right', available: true, formats: 'backup-*_dpanel.tar.gz' },
    { id: 'cpanel-partial', name: t('migrations.index.services.cpanelPartial.name'), description: t('migrations.index.services.cpanelPartial.description'), icon: 'bi bi-ui-checks-grid', available: true, formats: 'backup-DOMAIN-DATE.tar.gz, DB.sql.gz' },
    { id: 'directadmin', name: t('migrations.index.services.directadmin.name'), description: t('migrations.index.services.directadmin.description'), icon: 'bi bi-hdd-rack', available: true, formats: 'user.RESELLER.USER.tar.gz, .tar.zst' },
    { id: 'plesk', name: t('migrations.index.services.plesk.name'), description: t('migrations.index.services.plesk.description'), icon: 'bi bi-boxes', available: true, formats: 'Plesk backup .tar (unencrypted)' },
    { id: 'wordpress', name: t('migrations.index.services.wordpress.name'), description: t('migrations.index.services.wordpress.description'), icon: 'bi bi-wordpress', available: true, formats: '.zip / .tar.gz + .sql' },
]);
const serviceRoutes = {
    'cpanel-full': 'migrations.cpanel',
    'cpanel-partial': 'migrations.cpanel-partial',
    directadmin: 'migrations.directadmin',
    plesk: 'migrations.plesk',
    wordpress: 'migrations.wordpress',
    'dpanel-full': 'migrations.dpanel',
    'cyberpanel-ssh': 'migrations.cyberpanel-ssh',
};
const openService = (service) => {
    if (serviceRoutes[service.id]) router.visit(route(serviceRoutes[service.id]));
};
// Account backups from other panels share one upload → inspect → select → restore flow.
const accountArchives = computed(() => ({
    cpanel: {
        title: t('migrations.index.archives.cpanel.title'),
        description: t('migrations.index.archives.cpanel.description'),
        accept: '.gz,.tgz,application/gzip',
    },
    'cpanel-partial': {
        title: t('migrations.index.archives.cpanelPartial.title'),
        description: t('migrations.index.archives.cpanelPartial.description'),
        accept: '.gz,.tgz,.sql,application/gzip',
    },
    directadmin: {
        title: t('migrations.index.archives.directadmin.title'),
        description: t('migrations.index.archives.directadmin.description'),
        accept: '.gz,.tgz,.zst,.tar',
    },
    plesk: {
        title: t('migrations.index.archives.plesk.title'),
        description: t('migrations.index.archives.plesk.description'),
        accept: '.tar,.gz,.tgz,.zst',
    },
}));
const accountArchive = computed(() => accountArchives.value[props.provider] || null);
const pageTitle = computed(() => accountArchive.value?.title || ({
    dpanel: t('migrations.index.title.dpanel'),
    'cyberpanel-ssh': t('migrations.index.title.cyberpanelSsh'),
    wordpress: t('migrations.index.title.wordpress'),
})[props.provider] || t('migrations.index.title.default'));
const state = (item) => choices[item.id] ||= { domains: [], files: [], databases: [], full_account: false };
const items = (item, type) => item.inventory?.[type] || [];
const allChecked = (item, type) => items(item, type).length > 0 && state(item)[type].length === items(item, type).length;
const toggleAll = (item, type) => state(item)[type] = allChecked(item, type) ? [] : items(item, type).map(x => x.id);
const hasSelection = (item) => state(item).full_account || ['domains', 'files', 'databases'].some(k => state(item)[k].length);
const total = computed(() => props.imports.length);
let refreshTimer = null;
const hasBackgroundWork = computed(() => props.imports.some(item => ['uploading', 'inspecting', 'restoring'].includes(item.status)));
onMounted(() => {
    refreshTimer = window.setInterval(() => {
        if (hasBackgroundWork.value) router.reload({ only: ['imports'], preserveScroll: true });
    }, 3000);
});
onBeforeUnmount(() => window.clearInterval(refreshTimer));

const upload = async () => {
    if (props.provider === 'cpanel-partial') return uploadPartial();
    if (!archive.value) return;
    uploading.value = true; error.value = ''; message.value = '';
    if (props.provider === 'dpanel') {
        try {
            const prepared = await axios.post(route('migrations.dpanel.prepare'), {
                archive_name: archive.value.name,
                archive_size: archive.value.size,
            });
            const { tracking_id: trackingId, chunk_size: chunkSize, total_chunks: totalChunks } = prepared.data;
            let nextChunk = 0;
            let completedChunks = 0;
            const workers = Array.from({ length: 3 }, async () => {
                while (nextChunk < totalChunks) {
                    const index = nextChunk++;
                    const form = new FormData();
                    form.append('index', String(index));
                    form.append('total', String(totalChunks));
                    form.append('chunk', archive.value.slice(index * chunkSize, Math.min((index + 1) * chunkSize, archive.value.size)), `archive.${index}.part`);
                    await axios.post(route('migrations.dpanel.chunks', { migrationImport: trackingId }), form, { timeout: 0 });
                    completedChunks++;
                    message.value = t('migrations.index.chunkProgress', { done: completedChunks, total: totalChunks });
                }
            });
            await Promise.all(workers);
            await axios.post(route('migrations.dpanel.complete', { migrationImport: trackingId }), { total: totalChunks }, { timeout: 0 });
            message.value = t('migrations.index.uploadComplete');
            archive.value = null;
            router.reload();
        } catch (e) {
            error.value = e.response?.data?.message || t('migrations.index.packageUploadFailed');
        } finally {
            uploading.value = false;
        }
        return;
    }
    const data = new FormData(); data.append('provider', props.provider); data.append('archive', archive.value);
    try { const response = await axios.post(route('migrations.store'), data); message.value = response.data.message; router.reload(); }
    catch (e) { error.value = e.response?.data?.message || t('migrations.index.uploadFailed'); }
    finally { uploading.value = false; }
};
const uploadPartial = async () => {
    if (!partialFiles.value.length) return;
    uploading.value = true; error.value = ''; message.value = '';
    const data = new FormData();
    data.append('provider', 'cpanel-partial');
    data.append('account', partial.account.trim());
    if (partial.domain.trim()) data.append('domain', partial.domain.trim());
    partialFiles.value.forEach((file) => data.append('archives[]', file));
    try {
        const response = await axios.post(route('migrations.store'), data);
        message.value = response.data.message;
        partialFiles.value = [];
        router.reload();
    } catch (e) {
        error.value = e.response?.data?.message || t('migrations.index.uploadFailed');
    } finally {
        uploading.value = false;
    }
};
const restoreDpanel = async (item) => {
    restoring.value = item.id; error.value = ''; message.value = '';
    try {
        const response = await axios.post(route('migrations.dpanel.restore', { migrationImport: item.id }), { confirmation: confirmation.value });
        message.value = response.data.message;
        confirmation.value = '';
        router.reload();
    } catch (e) {
        error.value = e.response?.data?.message || t('migrations.index.restoreNotStarted');
    } finally {
        restoring.value = null;
    }
};
const restore = async (item) => {
    restoring.value = item.id; error.value = ''; message.value = '';
    try { const response = await axios.post(route('migrations.restore', item.id), state(item)); message.value = response.data.message; }
    catch (e) { error.value = e.response?.data?.message || t('migrations.index.restoreFailed'); }
    finally { restoring.value = null; }
};
const remove = async (item) => {
    if (!confirm(t('migrations.index.removeConfirm', { name: item.original_name }))) return;
    await axios.delete(route('migrations.destroy', item.id)); router.reload();
};
</script>

<template>
  <Head :title="pageTitle" />
  <AuthenticatedLayout>
    <template #header><h2 class="text-xl font-semibold text-slate-800 dark:text-slate-100">{{ pageTitle }}</h2></template>
    <div class="mx-auto space-y-6 p-4 sm:p-6">
      <section v-if="!props.provider">
        <div class="mb-4"><h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('migrations.index.chooseService') }}</h3><p class="text-sm text-slate-500 dark:text-slate-400">{{ t('migrations.index.chooseServiceHelp') }}</p></div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <button v-for="service in services.filter(item => item.id !== 'dpanel-full' || props.canDpanelMigration)" :key="service.id" type="button" :disabled="!service.available" class="relative rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition dark:border-slate-800 dark:bg-slate-900/50" :class="service.available ? 'hover:border-indigo-300 hover:shadow-md dark:hover:border-indigo-700' : 'cursor-not-allowed opacity-60'" @click="openService(service)">
            <span v-if="!service.available" class="absolute right-4 top-4 rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('migrations.index.comingSoon') }}</span>
            <i :class="service.icon" class="text-2xl text-indigo-600 dark:text-indigo-400"></i>
            <h4 class="mt-3 font-semibold text-slate-900 dark:text-slate-100">{{ service.name }}</h4>
            <p class="mt-1 min-h-10 text-sm text-slate-500 dark:text-slate-400">{{ service.description }}</p>
            <p class="mt-3 text-xs font-medium text-slate-400 dark:text-slate-500">{{ service.formats }}</p>
          </button>
        </div>
      </section>

      <CyberPanelSshMigration v-else-if="props.provider === 'cyberpanel-ssh'" :saved-connections="props.savedSshConnections" :php-versions="props.phpVersions" />
      <template v-else-if="props.provider === 'wordpress'">
      <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300" @click="router.visit(route('migrations.index'))"><i class="bi bi-arrow-left"></i> {{ t('migrations.index.allServices') }}</button>
      <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('migrations.index.title.wordpress') }}</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><i18n-t keypath="migrations.index.wp.intro" scope="global"><template #config><code>wp-config.php</code></template><template #cmd><code>wp db export</code></template></i18n-t></p>
        <ol class="mt-4 list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
          <li>{{ t('migrations.index.wp.step1') }}</li>
          <li><i18n-t keypath="migrations.index.wp.step2" scope="global"><template #config><code>wp-config.php</code></template></i18n-t></li>
          <li><i18n-t keypath="migrations.index.wp.step3" scope="global"><template #a><code>siteurl</code></template><template #b><code>home</code></template></i18n-t></li>
        </ol>
      </section>
      <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('migrations.index.wp.target') }}</h3>
        <div v-if="props.websites.length" class="mt-4 divide-y divide-slate-200 dark:divide-slate-800">
          <div v-for="site in props.websites" :key="site.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
            <div class="min-w-0"><p class="break-all font-medium text-slate-900 dark:text-slate-100">{{ site.domain }}</p><p class="text-xs text-slate-500 dark:text-slate-400">{{ site.site_owner }}</p></div>
            <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" @click="router.visit(route('websites.import.index', { id: site.id }))">{{ t('migrations.index.wp.importHere') }}</button>
          </div>
        </div>
        <p v-else class="mt-3 text-sm text-slate-500 dark:text-slate-400"><i18n-t keypath="migrations.index.wp.noWebsite" scope="global"><template #link><button type="button" class="font-semibold text-indigo-600 dark:text-indigo-400" @click="router.visit(route('websites.create'))">{{ t('migrations.index.wp.createWebsite') }}</button></template></i18n-t></p>
      </section>
      </template>
      <template v-else-if="accountArchive || props.provider === 'dpanel'">
      <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300" @click="router.visit(route('migrations.index'))"><i class="bi bi-arrow-left"></i> {{ t('migrations.index.allServices') }}</button>
      <PanelLink v-if="props.provider === 'dpanel'" />
      <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ props.provider === 'dpanel' ? t('migrations.index.dpanel.title') : accountArchive.title }}</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ props.provider === 'dpanel' ? t('migrations.index.dpanel.description') : accountArchive.description }}</p>
        <div v-if="props.provider === 'dpanel'" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
          <b>{{ t('migrations.index.dpanel.warningTitle') }}</b> <i18n-t keypath="migrations.index.dpanel.warning" scope="global"><template #env><code>.env</code></template><template #storage><code>storage/app</code></template></i18n-t>
        </div>
        <form v-if="props.provider === 'cpanel-partial'" class="mt-5 space-y-3" @submit.prevent="upload">
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ t('migrations.index.partial.user') }}<input v-model.trim="partial.account" required pattern="[a-z_][a-z0-9_\-]*" maxlength="32" placeholder="cpaneluser" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ t('migrations.index.partial.domain') }} <span class="font-normal text-slate-400">{{ t('migrations.index.partial.domainHelp') }}</span><input v-model.trim="partial.domain" placeholder="example.com" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
          </div>
          <input type="file" multiple :accept="accountArchive.accept" required class="block w-full rounded-lg border border-slate-300 bg-white p-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:file:bg-slate-700 dark:file:text-slate-200" @change="partialFiles = Array.from($event.target.files || [])" />
          <p v-if="partialFiles.length" class="text-xs text-slate-500 dark:text-slate-400">{{ partialFiles.map(file => file.name).join(', ') }}</p>
          <button :disabled="uploading || !partialFiles.length || !partial.account" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ uploading ? t('migrations.index.inspecting') : t('migrations.index.uploadInspect') }}</button>
        </form>
        <form v-else class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="upload">
          <input type="file" :accept="props.provider === 'dpanel' ? '.tar.gz,application/gzip' : accountArchive.accept" required class="block w-full rounded-lg border border-slate-300 bg-white p-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:file:bg-slate-700 dark:file:text-slate-200" @change="archive = $event.target.files[0]" />
          <button :disabled="uploading" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ uploading ? (props.provider === 'dpanel' ? t('migrations.index.uploading') : t('migrations.index.inspecting')) : t('migrations.index.uploadInspect') }}</button>
        </form>
      </section>

      <div v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ message }}</div>
      <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ error }}</div>

      <div v-if="total" class="pt-2"><h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('migrations.index.uploaded') }}</h3><p class="text-sm text-slate-500 dark:text-slate-400">{{ t('migrations.index.uploadedHelp') }}</p></div>
      <section v-for="item in imports" :key="item.id" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div><h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ item.original_name }}</h3><p class="text-sm text-slate-500 dark:text-slate-400">{{ props.provider === 'dpanel' ? t('migrations.index.fullBackup') : t('migrations.index.account', { account: item.inventory?.account || item.inventory?.options?.account || '—' }) }} · {{ (item.archive_size / 1048576).toFixed(1) }} MB</p></div>
          <div class="flex items-center gap-3"><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="['ready', 'completed'].includes(item.status) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' : ['uploading', 'inspecting', 'restoring'].includes(item.status) ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400'">{{ te(`migrations.index.status.${item.status}`) ? t(`migrations.index.status.${item.status}`) : item.status }}</span><button :disabled="['inspecting', 'restoring'].includes(item.status)" class="text-sm text-red-600 disabled:opacity-40 dark:text-red-400" @click="remove(item)">{{ t('migrations.index.remove') }}</button></div>
        </div>
        <p v-if="item.last_error" class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ item.last_error }}</p>
        <div v-if="item.inventory?.restore_result" class="mt-3 space-y-2 text-sm">
          <p v-for="site in item.inventory.restore_result.connected" :key="site.domain" class="text-emerald-700 dark:text-emerald-300"><i class="bi bi-plug"></i> <i18n-t keypath="migrations.index.connected" scope="global"><template #site>{{ site.domain }}</template><template #framework>{{ site.framework }}</template><template #database><code>{{ site.database }}</code></template></i18n-t></p>
          <p v-for="warning in item.inventory.restore_result.warnings" :key="warning" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ warning }}</p>
        </div>

        <div v-if="props.provider === 'dpanel' && item.status === 'ready'" class="mt-5 space-y-4">
          <p class="text-sm text-emerald-700 dark:text-emerald-300">{{ item.inventory?.validation || t('migrations.index.validationDone') }}</p>
          <label class="block max-w-sm text-sm font-medium text-slate-700 dark:text-slate-300"><i18n-t keypath="migrations.index.typeRestore" scope="global"><template #word><code>RESTORE</code></template></i18n-t><input v-model.trim="confirmation" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 font-mono dark:border-slate-700 dark:bg-slate-800" /></label>
          <button :disabled="confirmation !== 'RESTORE' || restoring === item.id" class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="restoreDpanel(item)">{{ restoring === item.id ? t('migrations.index.startingRestore') : t('migrations.index.restoreFull') }}</button>
        </div>

        <div v-if="accountArchive && item.status === 'ready'" class="mt-5 space-y-5">
          <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-500/10"><input v-model="state(item).full_account" type="checkbox" class="rounded" /><span class="text-slate-900 dark:text-slate-100"><b>{{ t('migrations.index.fullAccount') }}</b><small class="block text-slate-600 dark:text-slate-400">{{ t('migrations.index.fullAccountHelp') }}</small></span></label>
          <div class="grid gap-4 lg:grid-cols-3" :class="{ 'pointer-events-none opacity-50': state(item).full_account }">
            <div v-for="type in ['domains', 'files', 'databases']" :key="type" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700 dark:bg-slate-800/40">
              <div class="mb-3 flex items-center justify-between"><h4 class="font-semibold capitalize text-slate-900 dark:text-slate-100">{{ t(`migrations.index.type.${type}`) }}</h4><button v-if="items(item,type).length" type="button" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400" @click="toggleAll(item,type)">{{ allChecked(item,type) ? t('migrations.index.clearAll') : t('migrations.index.selectAll') }}</button></div>
              <div v-if="!items(item,type).length" class="text-sm text-slate-400 dark:text-slate-500">{{ t('migrations.index.noneFound') }}</div>
              <label v-for="entry in items(item,type)" :key="entry.id" class="mb-2 flex cursor-pointer items-start gap-2 text-sm text-slate-700 dark:text-slate-300"><input v-model="state(item)[type]" :value="entry.id" type="checkbox" class="mt-0.5 rounded" /><span class="min-w-0 break-all">{{ entry.label }}<small v-if="entry.document_root" class="block text-slate-400 dark:text-slate-500">{{ entry.document_root }}</small></span></label>
            </div>
          </div>
          <button :disabled="!hasSelection(item) || restoring === item.id" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="restore(item)">{{ restoring === item.id ? t('migrations.index.restoring') : t('migrations.index.restoreSelected') }}</button>
        </div>
      </section>
      <p v-if="!total" class="py-12 text-center text-slate-500 dark:text-slate-400">{{ t('migrations.index.empty') }}</p>
      </template>
    </div>
  </AuthenticatedLayout>
</template>
