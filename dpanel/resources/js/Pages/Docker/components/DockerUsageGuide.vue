<script setup>
import { ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Step-by-step help: the usual way to serve an app is a Docker website, which
// gets a domain, SSL and the File Manager; this page is for everything else.
const storageKey = 'dpanel.docker.guide.open';
const readOpen = () => {
    try {
        return localStorage.getItem(storageKey) !== '0';
    } catch {
        return true;
    }
};
const open = ref(readOpen());
watch(open, (value) => {
    try {
        localStorage.setItem(storageKey, value ? '1' : '0');
    } catch {
        // Storage blocked: the guide just opens again next time.
    }
});

const page = usePage();
const websitesUrl = () => {
    const token = String(page.props.panel?.token || '');
    try {
        return token ? route('websites.list', { token }) : route('websites.list');
    } catch {
        return null;
    }
};

const siteSteps = [
    { get title() { return t('docker.usageGuide.steps.create.title'); }, get body() { return t('docker.usageGuide.steps.create.body'); } },
    { get title() { return t('docker.usageGuide.steps.runtime.title'); }, get body() { return t('docker.usageGuide.steps.runtime.body'); } },
    { get title() { return t('docker.usageGuide.steps.upload.title'); }, get body() { return t('docker.usageGuide.steps.upload.body'); } },
    { get title() { return t('docker.usageGuide.steps.open.title'); }, get body() { return t('docker.usageGuide.steps.open.body'); } },
];
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <button type="button" class="flex w-full items-center justify-between gap-3 p-6 text-left" @click="open = !open">
            <div>
                <h2 class="text-base font-semibold">{{ t('docker.usageGuide.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('docker.usageGuide.subtitle') }}</p>
            </div>
            <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ open ? t('docker.shared.hide') : t('docker.shared.show') }}</span>
        </button>

        <div v-if="open" class="space-y-6 border-t border-slate-200 p-6 dark:border-slate-800">
            <div>
                <h3 class="text-sm font-medium">{{ t('docker.usageGuide.siteTitle') }}</h3>
                <ol class="mt-3 space-y-4">
                    <li v-for="(step, i) in siteSteps" :key="step.title" class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-semibold text-white">{{ i + 1 }}</span>
                        <div>
                            <h4 class="text-sm font-medium">{{ step.title }}</h4>
                            <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ step.body }}</p>
                        </div>
                    </li>
                </ol>
                <Link v-if="websitesUrl()" :href="websitesUrl()" class="mt-4 inline-block text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ t('docker.usageGuide.goWebsites') }}</Link>
            </div>

            <div class="rounded-md border border-slate-200 p-4 dark:border-slate-700">
                <h3 class="text-sm font-medium">{{ t('docker.usageGuide.noDomainTitle') }}</h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    <i18n-t keypath="docker.usageGuide.noDomainBody" scope="global"><template #volume><code>my-app-data</code></template><template #site><code>dpanel-site-…</code></template></i18n-t>
                </p>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ t('docker.usageGuide.permNote') }}
            </p>
        </div>
    </section>
</template>
