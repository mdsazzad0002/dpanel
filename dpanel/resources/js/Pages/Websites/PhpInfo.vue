<script setup>
import { useI18n } from 'vue-i18n';
import { computed, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const { t } = useI18n();

const props = defineProps({ website: Object });
const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);

const loading = ref(false);
const error = ref('');
const html = ref('');
const version = ref('');
const frame = ref(null);

const load = async () => {
    loading.value = true; error.value = '';
    try {
        const response = await fetch(panelRoute('websites.php-info.render', { id: props.website.id }), {
            credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || t('websites.phpInfo.failed'));
        html.value = data.html || '';
        version.value = data.version || '';
    } catch (e) { error.value = e.message; } finally { loading.value = false; }
};

// The iframe is sandboxed without scripts; same-origin only lets us size it.
const resizeFrame = () => {
    const doc = frame.value?.contentDocument;
    if (doc?.body) frame.value.style.height = `${doc.documentElement.scrollHeight + 16}px`;
};

onMounted(load);
</script>

<template>
    <Head :title="t('websites.phpInfo.pageTitle', { domain: website.domain })" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div><h1 class="text-lg font-semibold">PHP Info</h1><p class="text-sm text-slate-500">{{ t('websites.phpInfo.subtitle', { domain: website.domain }) }}</p></div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700"><i class="bi bi-arrow-left mr-1"></i> {{ t('websites.sshKey.dashboard') }}</Link>
            </div>
        </template>

        <div class="mx-auto space-y-6 p-4 sm:p-6">
            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-500/10 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400"><i class="bi bi-filetype-php text-2xl"></i></div>
                    <div>
                        <p class="text-xs uppercase tracking-wider text-slate-500">{{ t('websites.phpInfo.running') }}</p>
                        <p class="text-2xl font-semibold text-slate-900 dark:text-white">{{ version || (loading ? '…' : (website.php_version || t('websites.status.unknown'))) }}</p>
                        <p class="text-xs text-slate-500">{{ t('websites.phpInfo.pool', { user: website.site_owner || t('websites.phpInfo.shared') }) }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><i class="bi bi-lock-fill mr-1"></i>{{ t('websites.phpInfo.private') }}</span>
                    <button type="button" :disabled="loading" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50" @click="load">
                        <i class="bi bi-arrow-clockwise mr-1"></i>{{ loading ? t('common.loading') : t('common.refresh') }}
                    </button>
                </div>
            </section>

            <div v-if="error" class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300"><i class="bi bi-exclamation-triangle-fill mr-2"></i>{{ error }}</div>

            <section v-if="html" class="overflow-hidden rounded-xl border bg-white shadow-sm dark:border-slate-700">
                <iframe ref="frame" :srcdoc="html" sandbox="allow-same-origin" title="phpinfo()" class="block w-full border-0" style="min-height: 70vh" @load="resizeFrame"></iframe>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
