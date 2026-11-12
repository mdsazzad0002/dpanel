<script setup>
import { useI18n } from 'vue-i18n';
import { computed, inject, ref } from 'vue';
import { Deferred, router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import SslBadgeMenu from '@/Pages/Websites/components/SslBadgeMenu.vue';
import CustomCertificateButton from '@/Pages/Websites/components/CustomCertificateButton.vue';
import WildcardSslButton from '@/Pages/Websites/components/WildcardSslButton.vue';
import SiteFavicon from '@/Pages/Websites/components/SiteFavicon.vue';

const { t } = useI18n();

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    sslStatus: {
        type: Object,
        default: () => ({}),
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

const emit = defineEmits(['edit-runtime']);

const runtimeLabel = computed(() => {
    const runtime = String(props.website?.runtime || 'php');
    if (runtime === 'node') return `Node.js ${props.website?.node_version || '-'}`;
    if (runtime === 'python') return `Python ${props.website?.python_version || '-'}`;
    if (runtime === 'docker') return `Docker ${props.website?.docker_image || '-'}`;
    return `PHP ${props.website?.php_version || '-'}`;
});

const pushToast = inject('pushToast');
const { panelRoute, requestJson } = usePanelApi();

// The connected database's auto-login, like DB Login on the Databases page:
// phpMyAdmin for MariaDB, pgAdmin for PostgreSQL, signed in as its own user.
const connectedDatabase = computed(() => {
    const list = props.databaseConnection?.databases || [];
    return list.find((db) => db.database_name === props.databaseConnection?.database_name) || list[0] || null;
});
const databaseLoginUrl = computed(() => {
    const db = connectedDatabase.value;
    if (!props.databaseConnection?.available || !db?.id) return '';
    return db.engine === 'postgresql'
        ? panelRoute('databases.pgadmin.autologin', { id: db.id })
        : panelRoute('databases.phpmyadmin.autologin', { id: db.id });
});
const databaseTool = computed(() => (connectedDatabase.value?.engine === 'postgresql' ? 'pgAdmin' : 'phpMyAdmin'));

const statusValue = computed(() => String(props.website?.status ?? 'unknown').toLowerCase());
const statusLabel = computed(() => {
    const value = statusValue.value;
    if (!value || value === 'unknown') return t('websites.status.unknown');
    if (value === 'live') return t('websites.status.live');
    if (value === 'disabled') return t('websites.status.disabled');
    return value.charAt(0).toUpperCase() + value.slice(1);
});
const statusDot = computed(() => {
    if (statusValue.value === 'live') return 'bg-emerald-500';
    if (statusValue.value === 'disabled') return 'bg-red-500';
    return 'bg-slate-400';
});
const statusClass = computed(() => {
    if (statusValue.value === 'live') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
    }
    if (statusValue.value === 'disabled') {
        return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    }
    return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
});

const sslEnabled = computed(() => Boolean(props.website?.enable_ssl));
const websiteSslStatus = computed(() => String(props.sslStatus?.status || 'unknown').toLowerCase());
const websiteSslLabel = computed(() => {
    const value = websiteSslStatus.value;
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : t('websites.status.unknown');
});
const websiteSslClass = computed(() => {
    if (websiteSslStatus.value === 'valid') return 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    if (['expired', 'invalid', 'failed'].includes(websiteSslStatus.value)) return 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300';
    if (websiteSslStatus.value === 'unreachable') return 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    return 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200';
});
const sslDaysRemaining = computed(() => {
    if (props.sslStatus?.days_remaining === null || props.sslStatus?.days_remaining === undefined || props.sslStatus?.days_remaining === '') return null;
    const value = Number(props.sslStatus?.days_remaining);
    return Number.isFinite(value) ? value : null;
});
const sslValidityLabel = computed(() => {
    const days = sslDaysRemaining.value;
    if (days === null) return '-';
    if (days < 0) return t('websites.hero.expiredAgo', { n: Math.abs(days) }, Math.abs(days));
    if (days === 0) return t('websites.hero.expiresToday');
    return t('websites.hero.days', { n: days }, days);
});
const sslCompactValidityClass = computed(() => {
    if (!sslEnabled.value) return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
    const days = sslDaysRemaining.value;
    if (days === null) return websiteSslClass.value;
    if (days < 0) return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    if (days <= 30) return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
});

const scheme = computed(() => (sslEnabled.value ? 'https' : 'http'));
const detectedApp = computed(() => String(props.rootInspection?.detected_app || '').toLowerCase());

const liveSiteUrl = computed(() => {
    const domain = String(props.website?.domain || '').trim();
    if (!domain) return '';
    return `${scheme.value}://${domain}`;
});

const copyToClipboard = (text, toastMessage = '') => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    }
    if (toastMessage) {
        pushToast?.(toastMessage, 'success');
    }
};

const sslActionLoading = ref(false);

const refreshSslStatus = () => {
    if (sslActionLoading.value) return;
    sslActionLoading.value = true;
    router.reload({
        only: ['website', 'sslStatus', 'autoRenewNotice'],
        preserveScroll: true,
        onSuccess: () => pushToast?.(t('websites.hero.sslRefreshed'), 'success'),
        onError: () => pushToast?.(t('websites.hero.sslRefreshFailed'), 'error'),
        onFinish: () => { sslActionLoading.value = false; },
    });
};

const issueWebsiteSsl = async () => {
    if (sslActionLoading.value) return;
    sslActionLoading.value = true;

    try {
        const data = await requestJson(panelRoute('websites.ssl.issue', { id: props.website.id }));
        pushToast?.(data.message || t('websites.hero.sslIssued'), 'success');
        // Give the gateway a moment to load the new certificate before the
        // live TLS check runs again.
        setTimeout(() => router.reload({ only: ['website', 'sslStatus', 'autoRenewNotice'], preserveScroll: true }), 1500);
    } catch (error) {
        pushToast?.(error?.message || t('websites.hero.sslIssueFailed'), 'error');
    } finally {
        sslActionLoading.value = false;
    }
};

// Reinstall and uninstall both just need the fresh SSL state.
const onSslChanged = (data) => {
    pushToast?.(data.message || t('websites.hero.sslUpdated'), 'success');
    router.reload({ only: ['website', 'sslStatus', 'autoRenewNotice'], preserveScroll: true });
};

const disableWebsiteSsl = async () => {
    if (sslActionLoading.value) return;
    if (!window.confirm(t('websites.hero.disableConfirm'))) return;
    sslActionLoading.value = true;

    try {
        const data = await requestJson(panelRoute('websites.ssl.status.update', { id: props.website.id }), {
            method: 'PATCH',
            body: { enabled: false },
        });
        pushToast?.(data.message || t('websites.hero.sslDisabled'), 'success');
        router.reload({ only: ['website', 'sslStatus', 'autoRenewNotice'], preserveScroll: true });
    } catch (error) {
        pushToast?.(error?.message || t('websites.hero.sslDisableFailed'), 'error');
    } finally {
        sslActionLoading.value = false;
    }
};
</script>

<template>
    <div class="space-y-5">
        <!-- Status Badges -->
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium" :class="statusClass">
                <span class="h-1.5 w-1.5 rounded-full" :class="statusDot"></span>
                {{ statusLabel }}
            </span>
            <button type="button"
                class="inline-flex items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:bg-blue-500/20"
                @click="emit('edit-runtime')">
                <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                </svg>
                {{ runtimeLabel }}
                <span class="border-l border-blue-200 pl-1.5 font-semibold dark:border-blue-800">{{ t('websites.hero.edit') }}</span>
            </button>
            <Deferred data="sslStatus">
                <template #fallback>
                    <span class="inline-flex h-[26px] w-24 animate-pulse items-center rounded-full border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800"></span>
                </template>
                <SslBadgeMenu :website="website" :badge-class="['px-3 py-1 text-xs', sslCompactValidityClass]" @reinstalled="onSslChanged" @uninstalled="onSslChanged" @failed="(message) => pushToast?.(message, 'error')">
                    <i class="bi bi-shield-check"></i>
                    <span>SSL</span>
                    <span class="opacity-40">|</span>
                    <span>{{ sslEnabled ? sslValidityLabel : t('websites.status.disabled') }}</span>
                </SslBadgeMenu>
            </Deferred>
        </div>

        <!-- Domain -->
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <SiteFavicon :url="liveSiteUrl" :domain="website.domain" box-class="h-9 w-9 rounded-lg text-sm" />
                <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-50">{{ website.domain || '-' }}</h2>
                <button v-if="liveSiteUrl" type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-blue-600 dark:hover:bg-slate-800 dark:hover:text-blue-400" @click="copyToClipboard(liveSiteUrl)" :title="t('websites.hero.copyUrl')" :aria-label="t('websites.hero.copyUrl')">
                    <i class="bi bi-copy text-sm"></i>
                </button>
                <a v-if="liveSiteUrl" :href="liveSiteUrl" target="_blank" rel="noopener noreferrer" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-emerald-600 dark:hover:bg-slate-800 dark:hover:text-emerald-400" :title="t('websites.hero.visit')" :aria-label="t('websites.hero.visit')">
                    <i class="bi bi-box-arrow-up-right text-sm"></i>
                </a>
            </div>
            <div class="mt-1.5 flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current opacity-50">
                    <path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z" />
                </svg>
                <span class="font-medium text-slate-700 dark:text-slate-300">{{ website.root_path || '-' }}</span>
                <button v-if="website.root_path" type="button" class="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-blue-600 dark:hover:bg-slate-800 dark:hover:text-blue-400" @click="copyToClipboard(website.root_path, t('websites.hero.pathCopied'))" :title="t('websites.hero.copyPath')" :aria-label="t('websites.hero.copyPath')">
                    <i class="bi bi-copy text-sm"></i>
                </button>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-300 dark:hover:bg-blue-500/20" @click="emit('edit-runtime')">
                    <span>{{ t('websites.hero.start', { dir: website.start_directory || t('websites.hero.rootPath') }) }}</span>
                    <span class="border-l border-blue-200 pl-1.5 font-semibold dark:border-blue-800">{{ t('websites.hero.edit') }}</span>
                </button>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <button type="button" :disabled="sslActionLoading" class="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:border-slate-300 hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" @click="refreshSslStatus">{{ sslActionLoading ? t('websites.hero.checking') : t('websites.hero.checkSsl') }}</button>
            <button type="button" :disabled="sslActionLoading" class="rounded-md border border-emerald-200 px-2.5 py-1.5 text-xs font-semibold text-emerald-600 hover:border-emerald-300 hover:bg-emerald-50 disabled:opacity-60 dark:border-emerald-800 dark:text-emerald-400 dark:hover:bg-emerald-500/10" @click="issueWebsiteSsl">{{ sslActionLoading ? t('websites.hero.processing') : t('websites.hero.issueSsl') }}</button>
            <CustomCertificateButton :website="website" :disabled="sslActionLoading" @installed="onSslChanged" />
            <WildcardSslButton :website="website" :disabled="sslActionLoading" @changed="onSslChanged" @failed="(message) => pushToast?.(message, 'error')" />
            <button v-if="sslEnabled" type="button" :disabled="sslActionLoading" class="rounded-md border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:border-red-300 hover:bg-red-50 disabled:opacity-60 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-500/10" @click="disableWebsiteSsl">{{ sslActionLoading ? t('websites.hero.processing') : t('websites.hero.disableSsl') }}</button>
        </div>
    </div>

    <div class="space-y-3 lg:col-start-1 lg:row-start-2">
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-2.5 dark:border-slate-700/80 dark:bg-slate-800/30">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ t('websites.hero.projectType') }}</p>
                <Deferred data="rootInspection">
                    <template #fallback>
                        <div class="mt-2 h-4 w-24 animate-pulse rounded bg-slate-200 dark:bg-slate-700"></div>
                    </template>
                    <p class="mt-1.5 text-sm font-semibold capitalize text-slate-700 dark:text-slate-200">{{ detectedApp || t('websites.hero.generic') }}</p>
                </Deferred>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-2.5 dark:border-slate-700/80 dark:bg-slate-800/30">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ t('websites.hero.dbConnection') }}</p>
                <div class="mt-1.5 flex items-center justify-between gap-2">
                    <p class="min-w-0 truncate text-sm font-semibold" :class="databaseConnection.available ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ databaseConnection.available ? databaseConnection.database_name : t('websites.hero.noDb') }}</p>
                    <a
                        v-if="databaseLoginUrl"
                        :href="databaseLoginUrl"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md border border-blue-300 px-1.5 py-0.5 text-[11px] font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20"
                        :title="t('websites.hero.openIn', { tool: databaseTool })"
                    >
                        <i class="bi bi-box-arrow-in-right"></i>
                        {{ t('websites.hero.login') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>
