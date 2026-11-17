<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProtectedFolders from './SiteProtection/ProtectedFolders.vue';
import UploadAndHotlink from './SiteProtection/UploadAndHotlink.vue';
import Firewall from './SiteProtection/Firewall.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    websites: { type: Array, default: () => [] },
    wafAvailable: { type: Boolean, default: false },
});

const search = ref('');
const selectedId = ref(props.websites[0]?.id ?? '');
const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.websites.filter((w) => !needle || w.domain.toLowerCase().includes(needle));
});
const selected = computed(() => props.websites.find((w) => w.id === selectedId.value) ?? null);
const isGuarded = (w) => w.directories.length > 0 || w.no_php_paths.length > 0 || (w.denied_paths?.length ?? 0) > 0 || (w.denied_extensions?.length ?? 0) > 0 || w.hotlink_protection || w.cloudflare_only || w.waf_mode === 'block';
</script>

<template>
    <Head :title="t('rules.siteProtection.title')" />
    <AuthenticatedLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold">{{ t('rules.siteProtection.title') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ t('rules.siteProtection.intro') }}</p>
            </div>

            <div v-if="websites.length === 0" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">
                {{ t('rules.siteProtection.empty') }}
            </div>

            <div v-else class="grid gap-6 lg:grid-cols-[16rem_1fr]">
                <aside class="space-y-2">
                    <input v-model="search" type="search" :placeholder="t('rules.siteProtection.search')" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <ul class="max-h-[60vh] overflow-y-auto rounded-md border border-slate-200 dark:border-slate-700">
                        <li v-for="website in filtered" :key="website.id">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800"
                                :class="website.id === selectedId ? 'bg-blue-50 font-medium text-blue-700 dark:bg-slate-800 dark:text-blue-300' : ''"
                                @click="selectedId = website.id"
                            >
                                <span class="truncate">{{ website.domain }}</span>
                                <i v-if="isGuarded(website)" class="bi bi-shield-check text-emerald-600" :title="t('rules.siteProtection.on')"></i>
                            </button>
                        </li>
                    </ul>
                </aside>

                <div v-if="selected" :key="selected.id" class="space-y-6">
                    <ProtectedFolders :website="selected" />
                    <Firewall :website="selected" :available="wafAvailable" />
                    <UploadAndHotlink :website="selected" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
