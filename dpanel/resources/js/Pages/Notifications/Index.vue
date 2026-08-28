<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    notifications: { type: Object, required: true },
    unreadCount: { type: Number, default: 0 },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const busy = ref(false);

const getNotificationIcon = (notification) => {
    if (notification.status === 'completed') return 'bi-check-circle-fill text-emerald-500';
    if (notification.status === 'blocked' || notification.status === 'failed') return 'bi-x-circle-fill text-red-500';
    if (notification.status === 'processing') return 'bi-arrow-repeat text-amber-500';
    return 'bi-info-circle-fill text-blue-500';
};

const openNotification = (notification) => {
    router.visit(panelRoute('notifications.show', { id: notification.id }));
};

const copyNotificationLink = async (notification, event) => {
    event.stopPropagation();
    const url = notification.data?.download_url;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
    } catch (e) {}
};

const downloadNotification = (notification, event) => {
    event.stopPropagation();
    const url = notification.data?.download_url;
    if (!url) return;
    const link = document.createElement('a');
    link.href = url;
    document.body.appendChild(link);
    link.click();
    link.remove();
};

const markAllAsRead = async () => {
    busy.value = true;
    try {
        await window.axios.post(panelRoute('notifications.read-all'));
        router.reload({ only: ['notifications', 'unreadCount'] });
    } finally {
        busy.value = false;
    }
};

const clearAll = async () => {
    if (!confirm('Clear all notifications? This cannot be undone.')) return;
    busy.value = true;
    try {
        await window.axios.delete(panelRoute('notifications.clear'));
        router.reload({ only: ['notifications', 'unreadCount'] });
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Head title="Notifications" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Notifications</h1>
                    <p class="text-sm text-slate-500">{{ unreadCount }} unread</p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        :disabled="busy || unreadCount === 0"
                        class="rounded-lg border px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        @click="markAllAsRead"
                    >
                        Mark all read
                    </button>
                    <button
                        type="button"
                        :disabled="busy || notifications.data.length === 0"
                        class="rounded-lg border px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        @click="clearAll"
                    >
                        Clear all
                    </button>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
            <section class="overflow-hidden rounded-xl border bg-white dark:border-slate-700 dark:bg-slate-900">
                <template v-if="notifications.data.length > 0">
                    <div
                        v-for="notification in notifications.data"
                        :key="notification.id"
                        class="flex cursor-pointer items-start gap-3 border-b border-slate-100 px-4 py-3 transition-colors last:border-b-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50"
                        :class="{ 'bg-blue-50/50 dark:bg-blue-900/10': !notification.read }"
                        @click="openNotification(notification)"
                    >
                        <span class="mt-0.5">
                            <i :class="['bi text-lg', getNotificationIcon(notification)]"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ notification.title }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ notification.message }}</p>
                            <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">{{ notification.time }}</p>
                            <div v-if="notification.data?.download_url" class="mt-2 flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700"
                                    @click="copyNotificationLink(notification, $event)"
                                >
                                    <i class="bi bi-clipboard mr-1"></i>Copy
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md bg-violet-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-violet-700"
                                    @click="downloadNotification(notification, $event)"
                                >
                                    <i class="bi bi-download mr-1"></i>Download
                                </button>
                            </div>
                        </div>
                        <span v-if="!notification.read" class="mt-2 h-2 w-2 shrink-0 rounded-full bg-blue-500"></span>
                    </div>
                </template>
                <div v-else class="px-4 py-14 text-center">
                    <i class="bi bi-bell-slash text-3xl text-slate-300 dark:text-slate-600"></i>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No notifications</p>
                </div>
            </section>

            <nav v-if="notifications.links.length > 3" class="flex flex-wrap items-center justify-center gap-1">
                <template v-for="(link, index) in notifications.links" :key="index">
                    <span
                        v-if="!link.url"
                        class="rounded-md px-3 py-1.5 text-xs text-slate-300 dark:text-slate-600"
                        v-html="link.label"
                    ></span>
                    <Link
                        v-else
                        :href="link.url"
                        preserve-scroll
                        class="rounded-md border px-3 py-1.5 text-xs dark:border-slate-700"
                        :class="link.active ? 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </AuthenticatedLayout>
</template>
