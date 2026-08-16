<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CyberPanelSshMigration from './components/CyberPanelSshMigration.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    provider: { type: String, default: null },
    imports: { type: Array, default: () => [] },
    savedSshConnections: { type: Array, default: () => [] },
    phpVersions: { type: Array, default: () => [] },
});
const archive = ref(null);
const uploading = ref(false);
const restoring = ref(null);
const message = ref('');
const error = ref('');
const choices = reactive({});
const services = [
    { id: 'cyberpanel-ssh', name: 'CyberPanel / HestiaCP / aaPanel / Plesk via SSH', description: 'Connect over SSH and discover websites with their databases. Panel type is auto-detected.', icon: 'bi bi-terminal', available: true, formats: 'SSH password or private key' },
    { id: 'cpanel-full', name: 'cPanel Full Backup', description: 'Restore a complete cpmove account or selected resources.', icon: 'bi bi-server', available: true, formats: 'cpmove-USER.tar.gz, .tgz' },
    { id: 'cpanel-partial', name: 'cPanel Partial Backup', description: 'Import Home Directory, MySQL, or domain backups separately.', icon: 'bi bi-ui-checks-grid', available: false, formats: 'Coming soon' },
    { id: 'directadmin', name: 'DirectAdmin Backup', description: 'Migrate users and selected domains from DirectAdmin.', icon: 'bi bi-hdd-rack', available: false, formats: 'Coming soon' },
    { id: 'plesk', name: 'Plesk Backup Archive', description: 'Import from an uploaded .tar Plesk backup file. For a live Plesk server, use CyberPanel / HestiaCP / aaPanel / Plesk via SSH above instead.', icon: 'bi bi-boxes', available: false, formats: 'Coming soon' },
    { id: 'wordpress', name: 'WordPress Migration', description: 'Import a WordPress archive and database package.', icon: 'bi bi-wordpress', available: false, formats: 'Coming soon' },
];
const openService = (service) => {
    if (service.id === 'cpanel-full') router.visit(route('migrations.cpanel'));
    if (service.id === 'cyberpanel-ssh') router.visit(route('migrations.cyberpanel-ssh'));
};
const state = (item) => choices[item.id] ||= { domains: [], files: [], databases: [], full_account: false };
const items = (item, type) => item.inventory?.[type] || [];
const allChecked = (item, type) => items(item, type).length > 0 && state(item)[type].length === items(item, type).length;
const toggleAll = (item, type) => state(item)[type] = allChecked(item, type) ? [] : items(item, type).map(x => x.id);
const hasSelection = (item) => state(item).full_account || ['domains', 'files', 'databases'].some(k => state(item)[k].length);
const total = computed(() => props.imports.length);
let refreshTimer = null;
const hasBackgroundWork = computed(() => props.imports.some(item => ['inspecting', 'restoring'].includes(item.status)));
onMounted(() => {
    refreshTimer = window.setInterval(() => {
        if (hasBackgroundWork.value) router.reload({ only: ['imports'], preserveScroll: true });
    }, 3000);
});
onBeforeUnmount(() => window.clearInterval(refreshTimer));

const upload = async () => {
    if (!archive.value) return;
    uploading.value = true; error.value = ''; message.value = '';
    const data = new FormData(); data.append('provider', 'cpanel'); data.append('archive', archive.value);
    try { const response = await axios.post(route('migrations.store'), data); message.value = response.data.message; router.reload(); }
    catch (e) { error.value = e.response?.data?.message || 'Upload failed.'; }
    finally { uploading.value = false; }
};
const restore = async (item) => {
    restoring.value = item.id; error.value = ''; message.value = '';
    try { const response = await axios.post(route('migrations.restore', item.id), state(item)); message.value = response.data.message; }
    catch (e) { error.value = e.response?.data?.message || 'Restore failed.'; }
    finally { restoring.value = null; }
};
const remove = async (item) => {
    if (!confirm(`Remove ${item.original_name}?`)) return;
    await axios.delete(route('migrations.destroy', item.id)); router.reload();
};
</script>

<template>
  <Head :title="props.provider === 'cpanel' ? 'cPanel Migration' : props.provider === 'cyberpanel-ssh' ? 'CyberPanel / HestiaCP / aaPanel / Plesk SSH Migration' : 'Migration Import'" />
  <AuthenticatedLayout>
    <template #header><h2 class="text-xl font-semibold text-slate-800 dark:text-slate-100">{{ props.provider === 'cpanel' ? 'cPanel Migration' : props.provider === 'cyberpanel-ssh' ? 'CyberPanel / HestiaCP / aaPanel / Plesk SSH Migration' : 'Migration Import' }}</h2></template>
    <div class="mx-auto space-y-6 p-4 sm:p-6">
      <section v-if="!props.provider">
        <div class="mb-4"><h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Choose migration service</h3><p class="text-sm text-slate-500 dark:text-slate-400">Select where the backup came from and what kind of package you have.</p></div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <button v-for="service in services" :key="service.id" type="button" :disabled="!service.available" class="relative rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition dark:border-slate-800 dark:bg-slate-900/50" :class="service.available ? 'hover:border-indigo-300 hover:shadow-md dark:hover:border-indigo-700' : 'cursor-not-allowed opacity-60'" @click="openService(service)">
            <span v-if="!service.available" class="absolute right-4 top-4 rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-800 dark:text-slate-400">Coming soon</span>
            <i :class="service.icon" class="text-2xl text-indigo-600 dark:text-indigo-400"></i>
            <h4 class="mt-3 font-semibold text-slate-900 dark:text-slate-100">{{ service.name }}</h4>
            <p class="mt-1 min-h-10 text-sm text-slate-500 dark:text-slate-400">{{ service.description }}</p>
            <p class="mt-3 text-xs font-medium text-slate-400 dark:text-slate-500">{{ service.formats }}</p>
          </button>
        </div>
      </section>

      <CyberPanelSshMigration v-else-if="props.provider === 'cyberpanel-ssh'" :saved-connections="props.savedSshConnections" :php-versions="props.phpVersions" />
      <template v-else-if="props.provider === 'cpanel'">
      <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300" @click="router.visit(route('migrations.index'))"><i class="bi bi-arrow-left"></i> All migration services</button>
      <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">cPanel Full Backup</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Supports cpmove-USER.tar.gz and .tgz. After inspection, restore the whole account or select domains, files, and databases independently.</p>
        <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="upload">
          <input type="file" accept=".gz,.tgz,application/gzip" required class="block w-full rounded-lg border border-slate-300 bg-white p-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:file:bg-slate-700 dark:file:text-slate-200" @change="archive = $event.target.files[0]" />
          <button :disabled="uploading" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ uploading ? 'Inspecting…' : 'Upload & Inspect' }}</button>
        </form>
      </section>

      <div v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ message }}</div>
      <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ error }}</div>

      <div v-if="total" class="pt-2"><h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Uploaded migrations</h3><p class="text-sm text-slate-500 dark:text-slate-400">Continue a full or partial restore from any retained archive.</p></div>
      <section v-for="item in imports" :key="item.id" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div><h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ item.original_name }}</h3><p class="text-sm text-slate-500 dark:text-slate-400">Account: {{ item.inventory?.account || '—' }} · {{ (item.archive_size / 1048576).toFixed(1) }} MB</p></div>
          <div class="flex items-center gap-3"><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="['ready', 'completed'].includes(item.status) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' : ['inspecting', 'restoring'].includes(item.status) ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400'">{{ item.status }}</span><button :disabled="['inspecting', 'restoring'].includes(item.status)" class="text-sm text-red-600 disabled:opacity-40 dark:text-red-400" @click="remove(item)">Remove</button></div>
        </div>
        <p v-if="item.last_error" class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ item.last_error }}</p>

        <div v-if="item.status === 'ready'" class="mt-5 space-y-5">
          <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-500/10"><input v-model="state(item).full_account" type="checkbox" class="rounded" /><span class="text-slate-900 dark:text-slate-100"><b>Restore full account</b><small class="block text-slate-600 dark:text-slate-400">All domains, home files, and databases</small></span></label>
          <div class="grid gap-4 lg:grid-cols-3" :class="{ 'pointer-events-none opacity-50': state(item).full_account }">
            <div v-for="type in ['domains', 'files', 'databases']" :key="type" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700 dark:bg-slate-800/40">
              <div class="mb-3 flex items-center justify-between"><h4 class="font-semibold capitalize text-slate-900 dark:text-slate-100">{{ type }}</h4><button v-if="items(item,type).length" type="button" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400" @click="toggleAll(item,type)">{{ allChecked(item,type) ? 'Clear all' : 'Select all' }}</button></div>
              <div v-if="!items(item,type).length" class="text-sm text-slate-400 dark:text-slate-500">None found</div>
              <label v-for="entry in items(item,type)" :key="entry.id" class="mb-2 flex cursor-pointer items-start gap-2 text-sm text-slate-700 dark:text-slate-300"><input v-model="state(item)[type]" :value="entry.id" type="checkbox" class="mt-0.5 rounded" /><span class="min-w-0 break-all">{{ entry.label }}<small v-if="entry.document_root" class="block text-slate-400 dark:text-slate-500">{{ entry.document_root }}</small></span></label>
            </div>
          </div>
          <button :disabled="!hasSelection(item) || restoring === item.id" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="restore(item)">{{ restoring === item.id ? 'Restoring…' : 'Restore selected items' }}</button>
        </div>
      </section>
      <p v-if="!total" class="py-12 text-center text-slate-500 dark:text-slate-400">No migration archive uploaded yet.</p>
      </template>
    </div>
  </AuthenticatedLayout>
</template>
