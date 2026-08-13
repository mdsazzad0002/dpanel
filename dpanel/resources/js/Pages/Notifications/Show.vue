<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    notification: { type: Object, required: true },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const toasts = ref([]);
let toastSeq = 0;
const pushToast = (message, type = 'success') => {
    const id = ++toastSeq;
    toasts.value.push({ id, message, type });
    window.setTimeout(() => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }, 4000);
};

const statusMeta = computed(() => {
    switch (props.notification.status) {
        case 'completed': return { label: 'Completed', icon: 'bi-check-circle-fill', class: 'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/20' };
        case 'blocked':
        case 'failed': return { label: 'Failed', icon: 'bi-x-circle-fill', class: 'text-red-600 bg-red-50 dark:bg-red-900/20' };
        case 'processing': return { label: 'Processing', icon: 'bi-arrow-repeat', class: 'text-amber-600 bg-amber-50 dark:bg-amber-900/20' };
        default: return { label: 'Info', icon: 'bi-info-circle-fill', class: 'text-blue-600 bg-blue-50 dark:bg-blue-900/20' };
    }
});

const downloadUrl = computed(() => props.notification.data?.download_url || null);

const triggerDownload = () => {
    if (!downloadUrl.value) return;
    const link = document.createElement('a');
    link.href = downloadUrl.value;
    document.body.appendChild(link);
    link.click();
    link.remove();
};

const copyDownloadLink = async () => {
    if (!downloadUrl.value) return;
    try {
        await navigator.clipboard.writeText(downloadUrl.value);
        pushToast('Link copied to clipboard.');
    } catch {
        pushToast('Could not copy the link — copy it manually.', 'error');
    }
};
</script>

<template>
    <Head :title="`Notification - ${notification.title}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Notification</h1>
                    <p class="text-sm text-slate-500">{{ notification.time }}</p>
                </div>
                <Link :href="panelRoute('dashboard')" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700">
                    <i class="bi bi-arrow-left mr-1"></i> Back to dashboard
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="statusMeta.class">
                        <i class="bi text-lg" :class="statusMeta.icon"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ notification.title }}</h2>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusMeta.class">{{ statusMeta.label }}</span>
                        </div>
                        <p v-if="notification.message" class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ notification.message }}</p>
                        <p class="mt-2 text-xs text-slate-400">{{ notification.created_at }}</p>
                    </div>
                </div>

                <div v-if="downloadUrl" class="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">Export file</span>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                                @click="copyDownloadLink"
                            >
                                <i class="bi bi-clipboard mr-1"></i>Copy link
                            </button>
                            <button
                                type="button"
                                class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700"
                                @click="triggerDownload"
                            >
                                <i class="bi bi-download mr-1"></i>Download
                            </button>
                        </div>
                    </div>
                    <a :href="downloadUrl" class="mt-2 block break-all text-xs text-blue-600 hover:underline dark:text-blue-400">{{ downloadUrl }}</a>
                    <p class="mt-1 text-[11px] text-slate-400">No panel login needed — safe to share with anyone.</p>
                </div>
            </section>
        </div>

        <Teleport to="body">
            <div class="pointer-events-none fixed bottom-4 right-4 z-[80] flex flex-col gap-2">
                <div v-for="toast in toasts" :key="toast.id" class="pointer-events-auto rounded-lg px-4 py-2.5 text-sm font-medium text-white shadow-lg" :class="toast.type === 'error' ? 'bg-red-600' : 'bg-emerald-600'">
                    {{ toast.message }}
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
