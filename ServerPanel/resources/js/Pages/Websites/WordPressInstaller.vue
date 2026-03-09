<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    wordpressVersions: {
        type: Array,
        default: () => ['latest'],
    },
});

const page = usePage();

const installerValue = computed(() => String(props.website?.app_installer ?? 'none').toLowerCase());
const websiteWordPressVersion = computed(() => {
    const normalized = String(props.website?.wordpress_version ?? 'latest').trim().toLowerCase();
    return normalized === '' ? 'latest' : normalized;
});
const isWordPressInstalled = computed(() => installerValue.value === 'wordpress');

const availableWordPressVersions = computed(() => {
    const list = Array.isArray(props.wordpressVersions) ? props.wordpressVersions : [];
    const normalized = list
        .map((version) => String(version || '').trim().toLowerCase())
        .filter((version) => version === 'latest' || /^\d+\.\d+(\.\d+)?$/.test(version));

    return Array.from(new Set(['latest', websiteWordPressVersion.value, ...normalized]));
});

const selectedWordPressVersion = ref(websiteWordPressVersion.value);
</script>

<template>
    <Head title="WordPress Installer" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">WordPress Installer</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Install WordPress with one click for {{ website.domain || '-' }}.
                    </p>
                </div>

            </div>
        </template>

            <div class="flex justify-end mb-6">
                <Link :href="route('websites.manage', website.id)" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                   <i class="bi bi-arrow-left mr-2"></i> Back to Manage
                </Link>
            </div>


        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Domain</p>
                        <p class="mt-1 text-sm font-semibold break-all">{{ website.domain || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Root Path</p>
                        <p class="mt-1 text-sm font-semibold break-all">{{ website.root_path || '-' }}</p>
                    </div>
                </div>

                <div class="mt-3">
                    <span
                        class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="isWordPressInstalled
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                            : 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'"
                    >
                        {{ isWordPressInstalled
                            ? `Installed (${websiteWordPressVersion === 'latest' ? 'latest' : websiteWordPressVersion})`
                            : 'Not Installed' }}
                    </span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">WordPress Version</label>
                        <select
                            v-model="selectedWordPressVersion"
                            :disabled="isWordPressInstalled"
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800"
                        >
                            <option v-for="version in availableWordPressVersions" :key="version" :value="version">
                                {{ version === 'latest' ? 'Latest Stable' : version }}
                            </option>
                        </select>
                    </div>
                    <Link
                        :href="route('websites.wordpress.install', website.id)"
                        method="post"
                        as="button"
                        :data="{ wordpress_version: selectedWordPressVersion, return_to: 'wordpress' }"
                        :disabled="isWordPressInstalled"
                        class="rounded-md border px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                        :class="isWordPressInstalled
                            ? 'border-slate-300 text-slate-500 dark:border-slate-700 dark:text-slate-400'
                            : 'border-blue-300 text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20'"
                    >
                        {{ isWordPressInstalled ? 'WordPress Installed' : 'Install WordPress' }}
                    </Link>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
