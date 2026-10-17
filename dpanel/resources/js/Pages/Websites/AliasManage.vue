<script setup>
import { provide, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import ToastStack from '@/Pages/Websites/components/ToastStack.vue';
import AliasSslCard from '@/Pages/Websites/components/alias/AliasSslCard.vue';
import AliasDomainCard from '@/Pages/Websites/components/alias/AliasDomainCard.vue';

defineProps({
    website: { type: Object, required: true },
    parent: { type: Object, default: null },
    sslStatus: { type: Object, default: null },
});

const { panelRoute } = usePanelApi();

const toasts = ref([]);
let toastSeq = 0;

const removeToast = (id) => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
};

const pushToast = (message, type = 'error') => {
    if (!message) return;

    const id = `${Date.now()}-${toastSeq += 1}`;
    toasts.value.push({ id, message: String(message), type });
    window.setTimeout(() => removeToast(id), 3500);
};

provide('pushToast', pushToast);
</script>

<template>
    <Head :title="`Alias ${website.domain}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="min-w-0">
                <nav class="flex min-w-0 items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                    <Link :href="panelRoute('dashboard')" class="hover:text-slate-700 dark:hover:text-slate-300">
                        <i class="bi bi-house-door"></i>
                    </Link>
                    <i class="bi bi-chevron-right text-xs"></i>
                    <Link :href="panelRoute('websites.list')" class="hover:text-slate-700 dark:hover:text-slate-300">Websites</Link>
                    <template v-if="parent">
                        <i class="bi bi-chevron-right text-xs"></i>
                        <Link :href="panelRoute('websites.manage', { id: parent.id })" class="truncate hover:text-slate-700 dark:hover:text-slate-300">{{ parent.domain }}</Link>
                    </template>
                    <i class="bi bi-chevron-right text-xs"></i>
                    <span class="truncate font-medium text-slate-900 dark:text-slate-100">{{ website.domain }}</span>
                </nav>
                <h1 class="mt-2 text-lg font-semibold">Alias Management</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ website.domain }} serves the same files as
                    <Link v-if="parent" :href="panelRoute('websites.manage', { id: parent.id })" class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ parent.domain }}</Link>
                    <span v-else>its main website</span>.
                    File, database and app tools live on the main website.
                </p>
            </div>
        </template>

        <div class="space-y-6">
            <ToastStack :toasts="toasts" @dismiss="removeToast" />

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <AliasSslCard :website="website" :ssl-status="sslStatus" />
                <AliasDomainCard :website="website" :parent="parent" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
