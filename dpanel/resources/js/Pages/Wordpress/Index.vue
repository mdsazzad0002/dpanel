<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useWordpressApi } from './useWordpressApi';
import SiteList from './components/SiteList.vue';
import UpdatesTab from './components/UpdatesTab.vue';
import StagingTab from './components/StagingTab.vue';
import SecurityTab from './components/SecurityTab.vue';
import BulkUpdateBar from './components/BulkUpdateBar.vue';

const { t } = useI18n();
const api = useWordpressApi();

const sites = ref([]);
const loading = ref(false);
const error = ref('');
const selectedId = ref('');
const checked = ref([]);
const tab = ref('updates');

const selected = computed(() => sites.value.find((site) => site.id === selectedId.value) || null);

const load = async () => {
    loading.value = true;
    try {
        sites.value = (await api.get('wordpress.sites')).sites || [];
        if (!selected.value && sites.value.length) selectedId.value = sites.value[0].id;
        error.value = '';
    } catch (e) {
        error.value = api.message(e, t('wordpress.loadFailed'));
    } finally {
        loading.value = false;
    }
};

const versionChanged = (id, version) => {
    const site = sites.value.find((item) => item.id === id);
    if (site && version) site.version = version;
};

onMounted(load);
</script>

<template>
    <Head :title="t('wordpress.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('wordpress.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('wordpress.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
            <BulkUpdateBar v-if="checked.length" :sites="sites.filter((site) => checked.includes(site.id))" @done="load" @clear="checked = []" />

            <div class="grid gap-4 lg:grid-cols-[18rem_1fr]">
                <SiteList v-model:selected="selectedId" v-model:checked="checked" :sites="sites" :loading="loading" @refresh="load" />

                <section v-if="selected" class="min-w-0 rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3 dark:border-slate-800" role="tablist">
                        <p class="mr-auto px-1 font-semibold text-slate-800 dark:text-slate-100">{{ selected.domain }}</p>
                        <button v-for="name in ['updates', 'staging', 'security']" :key="name" type="button" role="tab" :aria-selected="tab === name"
                            class="rounded-lg px-3 py-1.5 text-sm font-semibold"
                            :class="tab === name ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                            @click="tab = name">
                            {{ t(`wordpress.tabs.${name}`) }}
                        </button>
                    </div>
                    <UpdatesTab v-if="tab === 'updates'" :key="`u-${selected.id}`" :site="selected" @version="versionChanged(selected.id, $event)" />
                    <StagingTab v-else-if="tab === 'staging'" :key="`s-${selected.id}`" :site="selected" />
                    <SecurityTab v-else :key="`c-${selected.id}`" :site="selected" />
                </section>
                <section v-else-if="!loading" class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                    {{ t('wordpress.none') }}
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
