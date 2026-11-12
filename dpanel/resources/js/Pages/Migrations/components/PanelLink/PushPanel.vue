<script setup>
// Send to the linked panel: pick this panel's websites, copy them there
// (created there if missing) or sync only changed files.
import axios from 'axios';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import WholePanelBox from './WholePanelBox.vue';

const props = defineProps({
    connection: { type: Object, required: true },
    localWebsites: { type: Array, default: () => [] },
});
const emit = defineEmits(['started']);
const { t } = useI18n();

const search = ref('');
const picked = ref([]);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const manageDns = ref(false);
const deleteMissing = ref(false);

const supported = (site) => ['php', 'static', ''].includes(site.runtime) && !['alis', 'alias'].includes(site.type);
const visible = computed(() => {
    const q = search.value.trim().toLowerCase();
    return q ? props.localWebsites.filter((site) => site.domain.toLowerCase().includes(q)) : props.localWebsites;
});
const selectable = computed(() => visible.value.filter(supported));
const allPicked = computed(() => selectable.value.length > 0 && selectable.value.every((site) => picked.value.includes(site.domain)));
const toggleAll = () => {
    picked.value = allPicked.value ? [] : selectable.value.map((site) => site.domain);
};

const start = async (kind, extra = {}) => {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const sites = props.localWebsites
            .filter((site) => picked.value.includes(site.domain))
            .map((site) => ({ domain: site.domain, type: ['subdomain', 'sub'].includes(site.type) ? 'sub' : 'main' }));
        const { data } = await axios.post(route('migrations.dpanel.link.connections.transfers', { connection: props.connection.id }), {
            direction: 'push',
            kind,
            sites: kind === 'panel' ? undefined : sites,
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
</script>

<template>
    <div>
        <p v-if="!connection.can_push" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('panelLink.noPushScope') }}</p>
        <template v-else>
            <div class="relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                <input v-model="search" :placeholder="t('panelLink.searchSites')" class="w-full rounded-lg border-slate-300 pl-8 text-sm dark:border-slate-700 dark:bg-slate-800" />
            </div>

            <p v-if="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ error }}</p>

            <div class="mt-3 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-3 border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:bg-slate-800/50">
                    <input type="checkbox" :checked="allPicked" :disabled="!selectable.length" class="rounded border-slate-300" @change="toggleAll" />
                    <span class="flex-1">{{ t('panelLink.websitesHere', { count: localWebsites.length }) }}</span>
                </div>
                <p v-if="!visible.length" class="px-3 py-6 text-center text-sm text-slate-500">{{ t('panelLink.noSites') }}</p>
                <ul v-else class="max-h-80 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                    <li v-for="site in visible" :key="site.id" class="flex items-center gap-3 px-3 py-2.5" :class="{ 'opacity-60': !supported(site) }">
                        <input v-model="picked" type="checkbox" :value="site.domain" :disabled="!supported(site)" class="rounded border-slate-300" />
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ site.domain }}</span>
                        <span v-if="!supported(site)" class="text-xs text-amber-600 dark:text-amber-400">{{ t('panelLink.notCopied') }}</span>
                    </li>
                </ul>
            </div>

            <div class="mt-4 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                <label class="flex items-start gap-2"><input v-model="manageDns" type="checkbox" class="mt-0.5 rounded border-slate-300" /><span>{{ t('panelLink.manageDnsThere') }}</span></label>
                <label class="flex items-start gap-2"><input v-model="deleteMissing" type="checkbox" class="mt-0.5 rounded border-slate-300" /><span>{{ t('panelLink.deleteMissingThere') }}</span></label>
            </div>

            <p v-if="notice" class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ notice }}</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" :disabled="busy || !picked.length" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" @click="start('website')">
                    <i class="bi bi-cloud-upload"></i> {{ t('panelLink.copyThere', { count: picked.length }) }}
                </button>
                <button type="button" :disabled="busy || !picked.length" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-500/10" @click="start('files')">
                    <i class="bi bi-arrow-left-right"></i> {{ t('panelLink.syncThere', { count: picked.length }) }}
                </button>
            </div>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('panelLink.copyThereHelp') }}</p>

            <WholePanelBox class="mt-6" direction="push" :name="connection.name" :busy="busy" @start="(extra) => start('panel', extra)" />
        </template>
    </div>
</template>
