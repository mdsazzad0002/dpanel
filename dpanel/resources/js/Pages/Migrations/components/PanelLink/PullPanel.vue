<script setup>
// Take from the linked panel: pick its websites, copy them here (files +
// database, the site is created if missing) or sync only changed files.
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import WholePanelBox from './WholePanelBox.vue';

const props = defineProps({
    connection: { type: Object, required: true },
    localUsers: { type: Array, default: () => [] },
});
const emit = defineEmits(['started']);
const { t } = useI18n();

const websites = ref([]);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const busy = ref(false);
const search = ref('');
const picked = ref([]);
const ownerId = ref(null);
const manageDns = ref(false);
const deleteMissing = ref(false);

const ownerOptions = computed(() => [
    { value: null, label: t('panelLink.ownerAuto') },
    ...props.localUsers.map((user) => ({ value: user.id, label: `${user.name} <${user.email}>`, keywords: user.email })),
]);

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();
    return q ? websites.value.filter((site) => site.domain.toLowerCase().includes(q) || (site.owner?.email || '').toLowerCase().includes(q)) : websites.value;
});
const selectable = computed(() => visible.value.filter((site) => site.supported));
const allPicked = computed(() => selectable.value.length > 0 && selectable.value.every((site) => picked.value.includes(site.domain)));

const toggleAll = () => {
    picked.value = allPicked.value ? [] : selectable.value.map((site) => site.domain);
};

const load = async () => {
    if (!props.connection.can_pull) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(route('migrations.dpanel.link.connections.inventory', { connection: props.connection.id }));
        websites.value = data.websites || [];
    } catch (e) {
        error.value = e.response?.data?.message || t('common.sessionExpired');
    } finally {
        loading.value = false;
    }
};

const start = async (kind, extra = {}) => {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const sites = websites.value
            .filter((site) => picked.value.includes(site.domain))
            .map((site) => ({ domain: site.domain, type: site.type, parent_domain: site.parent_domain, php_version: site.php_version, owner_email: site.owner?.email }));
        const { data } = await axios.post(route('migrations.dpanel.link.connections.transfers', { connection: props.connection.id }), {
            direction: 'pull',
            kind,
            sites: kind === 'panel' ? undefined : sites,
            owner_id: ownerId.value,
            manage_dns: manageDns.value,
            delete_missing: deleteMissing.value,
            ...extra,
        });
        emit('started', data.transfers);
        notice.value = data.skipped?.length ? t('panelLink.skippedBusy', { list: data.skipped.join(', ') }) : t('panelLink.started');
        picked.value = [];
    } catch (e) {
        error.value = e.response?.data?.message || t('common.sessionExpired');
    } finally {
        busy.value = false;
    }
};

onMounted(load);
</script>

<template>
    <div>
        <p v-if="!connection.can_pull" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('panelLink.noPullScope') }}</p>
        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1">
                    <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input v-model="search" :placeholder="t('panelLink.searchSites')" class="w-full rounded-lg border-slate-300 pl-8 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <button type="button" :disabled="loading" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="load">
                    <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': loading }"></i> {{ t('common.refresh') }}
                </button>
            </div>

            <p v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ error }}</p>

            <div class="mt-3 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-3 border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:bg-slate-800/50">
                    <input type="checkbox" :checked="allPicked" :disabled="!selectable.length" class="rounded border-slate-300" @change="toggleAll" />
                    <span class="flex-1">{{ t('panelLink.websitesThere', { count: websites.length }) }}</span>
                </div>
                <p v-if="loading && !websites.length" class="px-3 py-6 text-center text-sm text-slate-500">{{ t('common.loading') }}</p>
                <p v-else-if="!visible.length" class="px-3 py-6 text-center text-sm text-slate-500">{{ t('panelLink.noSites') }}</p>
                <ul v-else class="max-h-80 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                    <li v-for="site in visible" :key="site.id" class="flex items-start gap-3 px-3 py-2.5" :class="{ 'opacity-60': !site.supported }">
                        <input v-model="picked" type="checkbox" :value="site.domain" :disabled="!site.supported" class="mt-1 rounded border-slate-300" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-900 dark:text-slate-100">
                                {{ site.domain }}
                                <span v-if="site.type === 'sub'" class="ml-1 rounded bg-slate-100 px-1 text-[10px] text-slate-500 dark:bg-slate-800">{{ t('panelLink.subdomain') }}</span>
                            </p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                {{ site.owner ? site.owner.email : t('panelLink.noOwner') }}
                                · PHP {{ site.php_version || '—' }}
                                · {{ t('panelLink.dbCount', { count: site.databases.length }) }}
                                · {{ t('panelLink.mailCount', { count: site.mailboxes }) }}
                            </p>
                            <p v-if="!site.supported" class="text-xs text-amber-600 dark:text-amber-400">{{ site.unsupported_reason }}</p>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">
                    {{ t('panelLink.ownerHere') }}
                    <SearchableSelect v-model="ownerId" class="mt-1" :options="ownerOptions" :placeholder="t('panelLink.ownerAuto')" :search-placeholder="t('common.search')" />
                </label>
                <div class="space-y-2 pt-1 text-sm text-slate-700 dark:text-slate-300">
                    <label class="flex items-start gap-2"><input v-model="manageDns" type="checkbox" class="mt-0.5 rounded border-slate-300" /><span>{{ t('panelLink.manageDns') }}</span></label>
                    <label class="flex items-start gap-2"><input v-model="deleteMissing" type="checkbox" class="mt-0.5 rounded border-slate-300" /><span>{{ t('panelLink.deleteMissing') }}</span></label>
                </div>
            </div>

            <p v-if="notice" class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ notice }}</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" :disabled="busy || !picked.length" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" @click="start('website')">
                    <i class="bi bi-cloud-download"></i> {{ t('panelLink.copyHere', { count: picked.length }) }}
                </button>
                <button type="button" :disabled="busy || !picked.length" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-500/10" @click="start('files')">
                    <i class="bi bi-arrow-left-right"></i> {{ t('panelLink.syncHere', { count: picked.length }) }}
                </button>
            </div>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('panelLink.copyHelp') }}</p>

            <WholePanelBox class="mt-6" direction="pull" :name="connection.name" :busy="busy" @start="(extra) => start('panel', extra)" />
        </template>
    </div>
</template>
