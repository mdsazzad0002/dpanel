<script setup>
import { computed, inject, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: { type: Object, required: true },
    // Deferred: null until the TLS handshake check comes back.
    sslStatus: { type: Object, default: null },
});

const pushToast = inject('pushToast', () => {});
const { panelRoute, requestJson } = usePanelApi();
const busy = ref('');

const RESERVED_SSL_SUFFIXES = ['.localhost', '.local', '.test', '.example', '.invalid'];
const isLocalDomain = computed(() => {
    const domain = String(props.website.domain || '').toLowerCase();
    return domain === 'localhost' || RESERVED_SSL_SUFFIXES.some((suffix) => domain.endsWith(suffix));
});
const isIp = computed(() => /^(\d{1,3}\.){3}\d{1,3}$/.test(String(props.website.domain || '')));

const issuer = computed(() => {
    const issuerCn = props.sslStatus?.issuer_cn;
    if (!issuerCn) return null;
    return issuerCn === props.sslStatus?.subject_cn ? 'Self-signed' : issuerCn;
});

const validity = computed(() => {
    const days = props.sslStatus?.days_remaining;
    if (!props.website.enable_ssl) return { label: 'SSL disabled', tone: 'slate' };
    if (days === null || days === undefined) return { label: props.sslStatus?.status || 'Unknown', tone: 'slate' };
    if (days < 0) return { label: `Expired ${Math.abs(days)}d ago`, tone: 'red' };
    // IP certificates are short-lived by design, so only warn right before expiry.
    if (days <= (isIp.value ? 1 : 30)) return { label: `Valid ${days}d`, tone: 'amber' };
    return { label: `Valid ${days}d`, tone: 'emerald' };
});

const toneClasses = {
    slate: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
    red: 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400',
    amber: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
    emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400',
};

const formatDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
};

const refresh = () => router.reload({ only: ['website', 'sslStatus'] });

const issueSsl = async () => {
    if (busy.value) return;
    busy.value = 'issue';
    try {
        const data = await requestJson(panelRoute('websites.ssl.issue', { id: props.website.id }));
        pushToast(data.message || 'SSL issued successfully.', 'success');
        refresh();
    } catch (error) {
        pushToast(error.message || 'SSL issue failed.');
    } finally {
        busy.value = '';
    }
};

const toggleSsl = async () => {
    if (busy.value) return;
    busy.value = 'toggle';
    try {
        const data = await requestJson(panelRoute('websites.ssl.status.update', { id: props.website.id }), {
            method: 'PATCH',
            body: { enabled: !props.website.enable_ssl },
        });
        pushToast(data.message, 'success');
        refresh();
    } catch (error) {
        pushToast(error.message || 'SSL status update failed.');
    } finally {
        busy.value = '';
    }
};
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">SSL Certificate</h2>
            <span class="rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="toneClasses[validity.tone]">{{ validity.label }}</span>
        </div>

        <p v-if="isLocalDomain" class="mt-4 text-sm text-slate-500">
            SSL isn't available for local domains like .localhost/.local/.test — they aren't publicly resolvable.
        </p>
        <template v-else>
            <dl class="mt-4 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                <dt class="text-slate-500">Issuer</dt>
                <dd class="truncate">{{ sslStatus === null ? 'Checking…' : (issuer || '-') }}</dd>
                <dt class="text-slate-500">Expires</dt>
                <dd>{{ sslStatus === null ? 'Checking…' : formatDate(sslStatus?.valid_to) }}</dd>
                <dt class="text-slate-500">Status</dt>
                <dd class="truncate">{{ sslStatus === null ? 'Checking…' : (sslStatus?.message || sslStatus?.status || '-') }}</dd>
            </dl>
            <p v-if="isIp" class="mt-3 text-xs text-slate-500">
                A public IP gets a short-lived (~6 day) Let's Encrypt certificate that renews automatically; a private IP gets a self-signed one.
            </p>

            <div class="mt-5 flex flex-wrap gap-2">
                <button type="button" :disabled="Boolean(busy)"
                    class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                    @click="issueSsl">
                    {{ busy === 'issue' ? 'Issuing…' : 'Issue / Renew SSL' }}
                </button>
                <button type="button" :disabled="Boolean(busy)"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="toggleSsl">
                    {{ busy === 'toggle' ? 'Updating…' : (website.enable_ssl ? 'Disable SSL' : 'Enable SSL') }}
                </button>
            </div>
        </template>
    </section>
</template>
