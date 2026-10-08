<script setup>
import { ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

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
    { title: 'Create a website', body: 'Add the domain under Websites, as for any other site. Its folder, SSL and File Manager come with it.' },
    { title: 'Switch its runtime to Docker', body: 'On the website\'s Manage page open Runtime settings and pick Docker. Enter the image (for example nginx:alpine), the port the app listens on, and the path inside the container where the site folder should appear.' },
    { title: 'Upload your files', body: 'Use that website\'s File Manager: upload files or a zip and extract it in the site folder. The container sees the same folder, so you never upload into the container itself.' },
    { title: 'Open it', body: 'The domain works right away; the container\'s port stays on 127.0.0.1. Tick "Open port to the internet" on the Docker Service card only if you also want http://server-ip:port.' },
];
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <button type="button" class="flex w-full items-center justify-between gap-3 p-6 text-left" @click="open = !open">
            <div>
                <h2 class="text-base font-semibold">How to run an app with Docker</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">What to do in which order, and where your files go.</p>
            </div>
            <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ open ? 'Hide' : 'Show' }}</span>
        </button>

        <div v-if="open" class="space-y-6 border-t border-slate-200 p-6 dark:border-slate-800">
            <div>
                <h3 class="text-sm font-medium">Serve a website from a container (recommended)</h3>
                <ol class="mt-3 space-y-4">
                    <li v-for="(step, i) in siteSteps" :key="step.title" class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-semibold text-white">{{ i + 1 }}</span>
                        <div>
                            <h4 class="text-sm font-medium">{{ step.title }}</h4>
                            <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ step.body }}</p>
                        </div>
                    </li>
                </ol>
                <Link v-if="websitesUrl()" :href="websitesUrl()" class="mt-4 inline-block text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">Go to Websites →</Link>
            </div>

            <div class="rounded-md border border-slate-200 p-4 dark:border-slate-700">
                <h3 class="text-sm font-medium">Run a container without a domain</h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    For databases, workers or tools that need no website, use "Run a container" below. Keep data the container writes
                    in a named volume (a plain name such as <code>my-app-data</code>), which Docker creates and keeps across recreates.
                    Containers here and their sites appear in the list below; a site's container is named <code>dpanel-site-…</code>,
                    manage it from its website instead.
                </p>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">
                If an app cannot write to its site folder, it runs as a different user than the site. Keep that data in a named volume, or let it only read the folder.
            </p>
        </div>
    </section>
</template>
