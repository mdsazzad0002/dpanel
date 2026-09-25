<script setup>
import { computed, inject, ref, watch } from 'vue';
import { Deferred, Link, router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    rootInspection: {
        type: Object,
        default: () => ({}),
    },
    databaseConnection: {
        type: Object,
        default: () => ({ available: false }),
    },
});

const pushToast = inject('pushToast');
const { panelRoute, requestJson } = usePanelApi();

const isSystemWebsite = computed(() => String(props.website.id) === '1');
const detectedApp = computed(() => String(props.rootInspection?.detected_app || '').toLowerCase());
const canClearCache = computed(() => ['wordpress', 'laravel', 'codeigniter'].includes(detectedApp.value));
const isLaravelWebsite = computed(() => detectedApp.value === 'laravel');
const supportsDatabaseAutoConnect = computed(() => ['laravel', 'wordpress', 'codeigniter'].includes(detectedApp.value));
const detectedAppLabel = computed(() => ({ wordpress: 'WordPress', laravel: 'Laravel', codeigniter: 'CodeIgniter' }[detectedApp.value] || 'Project'));
const storageLinked = computed(() => Boolean(props.rootInspection?.storage_linked));

const cacheClearLoading = ref(false);
const permissionFixLoading = ref(false);
const databaseConnectLoading = ref(false);
const dependencyInstallLoading = ref('');
const storageLinkLoading = ref('');
const runProjectMigrationsLoading = ref(false);
const statusCheckLoading = ref(false);

const clearProjectCache = async () => {
    if (cacheClearLoading.value) return;
    cacheClearLoading.value = true;

    try {
        const data = await requestJson(panelRoute('websites.project-cache.clear', { id: props.website.id }));
        pushToast?.(data.message || 'Project cache cleared successfully.', 'success');
    } catch (error) {
        pushToast?.(error?.message || 'Project cache clear failed.', 'error');
    } finally {
        cacheClearLoading.value = false;
    }
};

const fixProjectPermissions = async () => {
    if (permissionFixLoading.value) return;
    permissionFixLoading.value = true;
    try {
        const data = await requestJson(panelRoute('websites.project-permissions.fix', { id: props.website.id }));
        pushToast?.(data.message || 'Project permissions fixed successfully.', 'success');
    } catch (error) {
        pushToast?.(error?.message || 'Permission repair failed.', 'error');
    } finally {
        permissionFixLoading.value = false;
    }
};

const availableDatabases = computed(() => props.databaseConnection?.databases || []);
const selectedDatabaseId = ref('');
watch(availableDatabases, (list) => {
    if (!list.some((db) => String(db.id) === selectedDatabaseId.value)) {
        selectedDatabaseId.value = list.length ? String(list[0].id) : '';
    }
}, { immediate: true });

const connectProjectDatabase = async () => {
    if (databaseConnectLoading.value || !props.databaseConnection?.available) return;
    databaseConnectLoading.value = true;
    try {
        const data = await requestJson(panelRoute('websites.project-database.connect', { id: props.website.id }), {
            body: availableDatabases.value.length > 1 ? { database_id: selectedDatabaseId.value } : {},
        });
        pushToast?.(data.message || 'Project database connected successfully.', 'success');
    } catch (error) {
        pushToast?.(error?.message || 'Database connection failed.', 'error');
    } finally {
        databaseConnectLoading.value = false;
    }
};

const installProjectDependencies = async (action) => {
    if (dependencyInstallLoading.value) return;
    dependencyInstallLoading.value = action;
    try {
        const data = await requestJson(panelRoute('websites.project-dependencies.install', { id: props.website.id }), {
            body: { action },
        });
        pushToast?.(data.message || 'Dependencies installed successfully.', 'success');
    } catch (error) {
        pushToast?.(error?.message || 'Dependency installation failed.', 'error');
    } finally {
        dependencyInstallLoading.value = '';
    }
};

const updateStorageLink = async (action) => {
    if (storageLinkLoading.value) return;
    storageLinkLoading.value = action;

    try {
        const data = await requestJson(panelRoute('websites.project-storage-link.update', { id: props.website.id }), {
            body: { action },
        });
        pushToast?.(data.message || 'Storage link updated successfully.', 'success');
        router.reload({ only: ['rootInspection'], preserveScroll: true });
    } catch (error) {
        pushToast?.(error?.message || 'Storage link action failed.', 'error');
    } finally {
        storageLinkLoading.value = '';
    }
};

const runProjectMigrations = async () => {
    if (runProjectMigrationsLoading.value) return;
    runProjectMigrationsLoading.value = true;

    try {
        const data = await requestJson(panelRoute('websites.project-migrate.run', { id: props.website.id }));
        pushToast?.(data.message || 'Migrations ran successfully.', 'success');
    } catch (error) {
        pushToast?.(error?.message || 'Migration failed.', 'error');
    } finally {
        runProjectMigrationsLoading.value = false;
    }
};

const checkWebsiteStatus = async () => {
    if (statusCheckLoading.value) return;
    statusCheckLoading.value = true;

    try {
        const data = await requestJson(panelRoute('websites.status.check', { id: props.website.id }));
        pushToast?.(data.message || 'Website status checked.', data.status === 'live' ? 'success' : 'error');
        router.reload({ only: ['website', 'activities'], preserveScroll: true });
    } catch (error) {
        pushToast?.(error?.message || 'Website status check failed.', 'error');
    } finally {
        statusCheckLoading.value = false;
    }
};
</script>

<template>
    <div class="mt-6 border-t border-slate-200 pt-5 dark:border-slate-800 lg:col-start-2 lg:row-start-1 lg:row-span-2 lg:mt-0 lg:border-t-0 lg:border-l lg:pl-6 lg:pt-0">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Quick Actions</p>
        <div class="mt-3 grid grid-cols-1 gap-2 lg:grid-cols-2">
            <Link v-if="!isSystemWebsite" :href="panelRoute('websites.filemanager', { id: website.id })" as="button"
                class="flex w-full items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left text-[13px] font-medium transition-all duration-150 border-emerald-200 bg-emerald-50/50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400 dark:hover:border-emerald-700">
                <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70">
                    <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                </svg>
                File Manager
            </Link>
            <Deferred data="rootInspection">
                <template #fallback>
                    <div v-for="n in 3" :key="n"
                        class="h-[42px] w-full animate-pulse rounded-xl border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800"></div>
                </template>
            <div v-if="isLaravelWebsite" class="grid gap-2" :class="storageLinked ? 'grid-cols-2' : 'grid-cols-1'">
                <button type="button" :disabled="Boolean(storageLinkLoading)"
                    class="flex w-full items-center gap-2 rounded-xl border border-blue-200 bg-blue-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-blue-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:border-blue-700"
                    @click="updateStorageLink(storageLinked ? 'refresh' : 'link')">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70"><path d="M3.9 12a5 5 0 0 1 1.46-3.54l3.1-3.1a5 5 0 0 1 7.07 7.07l-1.41 1.41-1.42-1.42 1.42-1.41a3 3 0 0 0-4.24-4.24l-3.1 3.1a3 3 0 0 0 4.24 4.24l.7-.7 1.42 1.42-.7.7A5 5 0 0 1 3.9 12zm6.97-2.83.7-.7a5 5 0 0 1 7.07 7.07l-3.1 3.1a5 5 0 0 1-7.07-7.07l1.41-1.41 1.42 1.42-1.42 1.41a3 3 0 0 0 4.24 4.24l3.1-3.1a3 3 0 0 0-4.24-4.24l-.7.7-1.42-1.42z" /></svg>
                    {{ storageLinkLoading ? 'Processing...' : (storageLinked ? 'Refresh Storage Link' : 'Link Storage') }}
                </button>
                <button v-if="storageLinked" type="button" :disabled="Boolean(storageLinkLoading)"
                    class="flex w-full items-center gap-2 rounded-xl border border-red-200 bg-red-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-red-700 transition hover:border-red-300 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400 dark:hover:border-red-700"
                    @click="updateStorageLink('unlink')">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70"><path d="M17 7h-3V5h3a5 5 0 0 1 0 10h-3v-2h3a3 3 0 0 0 0-6zM7 7h3V5H7a5 5 0 0 0 0 10h3v-2H7a3 3 0 0 1 0-6zm1 4h8V9H8v2zm-5.29 8.88 17.17-17.17 1.41 1.41L4.12 21.29l-1.41-1.41z" /></svg>
                    {{ storageLinkLoading === 'unlink' ? 'Unlinking...' : 'Unlink' }}
                </button>
            </div>
            </Deferred>
            <button type="button" :disabled="permissionFixLoading"
                class="flex w-full items-center gap-3 rounded-xl border border-amber-200 bg-amber-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-amber-700 transition hover:border-amber-300 hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400"
                @click="fixProjectPermissions">
                <i class="bi bi-wrench-adjustable-circle text-base"></i>
                {{ permissionFixLoading ? 'Fixing Permissions...' : 'Fix Permissions' }}
            </button>

            <Deferred data="rootInspection">
                <template #fallback>
                    <div v-for="n in 3" :key="n"
                        class="h-[42px] w-full animate-pulse rounded-xl border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800"></div>
                </template>
            <template v-if="supportsDatabaseAutoConnect">
                <Link v-if="!databaseConnection.available"
                    :href="panelRoute('databases.create') + '?domain=' + encodeURIComponent(website.domain)"
                    class="flex w-full items-center gap-3 rounded-xl border border-cyan-200 bg-cyan-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-cyan-700 transition hover:border-cyan-300 hover:bg-cyan-50 dark:border-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-400"
                >
                    <i class="bi bi-database-add text-base"></i>
                    Create Database
                </Link>
                <div v-else-if="availableDatabases.length > 1" class="space-y-1.5 rounded-xl border border-cyan-200 bg-cyan-50/50 p-2.5 dark:border-cyan-800 dark:bg-cyan-500/10">
                    <select v-model="selectedDatabaseId" class="w-full rounded-lg border border-cyan-200 bg-white px-2 py-1.5 text-[13px] text-cyan-700 dark:border-cyan-800 dark:bg-slate-900 dark:text-cyan-400">
                        <option v-for="db in availableDatabases" :key="db.id" :value="String(db.id)">{{ db.database_name }}</option>
                    </select>
                    <div class="flex items-center gap-2">
                        <button type="button" :disabled="databaseConnectLoading"
                            class="flex flex-1 items-center justify-center gap-2 rounded-lg border border-cyan-200 bg-white px-3 py-1.5 text-[12px] font-medium text-cyan-700 transition hover:border-cyan-300 disabled:cursor-not-allowed disabled:opacity-50 dark:border-cyan-800 dark:bg-slate-900 dark:text-cyan-400"
                            @click="connectProjectDatabase">
                            <i class="bi bi-database-check"></i>
                            {{ databaseConnectLoading ? 'Connecting...' : `Connect ${detectedAppLabel}` }}
                        </button>
                        <Link :href="panelRoute('databases.create') + '?domain=' + encodeURIComponent(website.domain)" class="shrink-0 text-[11px] font-medium text-cyan-700 underline decoration-dotted hover:text-cyan-900 dark:text-cyan-400">
                            + New
                        </Link>
                    </div>
                </div>
                <button v-else type="button"
                    :disabled="databaseConnectLoading"
                    :title="`Connect ${databaseConnection.database_name}`"
                    class="flex w-full items-center gap-3 rounded-xl border border-cyan-200 bg-cyan-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-cyan-700 transition hover:border-cyan-300 hover:bg-cyan-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-400"
                    @click="connectProjectDatabase">
                    <i class="bi bi-database-check text-base"></i>
                    {{ databaseConnectLoading ? 'Connecting Database...' : `Connect ${detectedAppLabel} Database` }}
                </button>
            </template>
            <button v-if="isLaravelWebsite" type="button"
                :disabled="runProjectMigrationsLoading"
                class="flex w-full items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400"
                @click="runProjectMigrations">
                <i class="bi bi-database-gear text-base"></i>
                {{ runProjectMigrationsLoading ? 'Running Migrations...' : 'Run Migrations' }}
            </button>
            <button v-if="rootInspection.has_composer_json" type="button"
                :disabled="Boolean(dependencyInstallLoading)"
                class="flex w-full items-center gap-3 rounded-xl border border-violet-200 bg-violet-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-violet-700 transition hover:border-violet-300 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-800 dark:bg-violet-500/10 dark:text-violet-400"
                @click="installProjectDependencies('composer_install')">
                <i class="bi bi-box-seam text-base"></i>
                {{ dependencyInstallLoading === 'composer_install' ? 'Installing Composer...' : 'Install Composer Dependencies' }}
            </button>
            <button v-if="rootInspection.has_package_json" type="button"
                :disabled="Boolean(dependencyInstallLoading)"
                class="flex w-full items-center gap-3 rounded-xl border border-red-200 bg-red-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-red-700 transition hover:border-red-300 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400"
                @click="installProjectDependencies('npm_install')">
                <i class="bi bi-node-plus text-base"></i>
                {{ dependencyInstallLoading === 'npm_install' ? 'Installing & Building...' : 'Install & Build NPM' }}
            </button>
            <button v-if="!isSystemWebsite && canClearCache" type="button" :disabled="cacheClearLoading"
                class="flex w-full items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left text-[13px] font-medium transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-60 border-red-200 bg-red-50/50 text-red-700 hover:border-red-300 hover:bg-red-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400 dark:hover:border-red-700"
                @click="clearProjectCache">
                <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70">
                    <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                </svg>
                {{ cacheClearLoading ? 'Clearing...' : `Clear ${{ wordpress: 'WordPress', laravel: 'Laravel', codeigniter: 'CodeIgniter' }[detectedApp]} Cache` }}
            </button>
            <button v-if="!isSystemWebsite && !canClearCache" type="button" :disabled="statusCheckLoading"
                class="flex w-full items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left text-[13px] font-medium transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-60 border-blue-200 bg-blue-50/50 text-blue-700 hover:border-blue-300 hover:bg-blue-50 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:border-blue-700"
                @click="checkWebsiteStatus">
                <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70">
                    <path d="M12 4a8 8 0 1 0 7.45 5H17l3.5-3.5L24 9h-2.55A10 10 0 1 1 12 2v2zm1 4h-2v5l4.25 2.52 1-1.72L13 11.9V8z" />
                </svg>
                {{ statusCheckLoading ? 'Checking...' : 'Check Status' }}
            </button>
            </Deferred>
        </div>
        <div class="mt-2">
            <Link :href="panelRoute('websites.list')" as="button"
                class="flex w-full items-center gap-3 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-left text-[13px] font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700">
                <i class="bi bi-arrow-left text-base opacity-70"></i>
                Back to List
            </Link>
        </div>
    </div>
</template>
