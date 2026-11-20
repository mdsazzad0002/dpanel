<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

// Addresses the WAF banned after repeated attacks. drust lifts each ban on
// its own when the time is up; an admin can lift one early.
const { t } = useI18n();
const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (token.value ? route(name, { token: token.value, ...params }) : route(name, params));

const bans = ref([]);
const loading = ref(false);
const lifting = ref('');
const error = ref('');
const notice = ref('');

const errorMessage = (e, fallback) => {
    if (e?.response?.status === 419) return t('common.sessionExpired');
    return e?.response?.data?.message || e?.message || fallback;
};

const load = async () => {
    loading.value = true;
    try {
        const { data } = await window.axios.get(panelRoute('security.firewall.waf-bans'));
        bans.value = data.bans || [];
        error.value = '';
    } catch (e) {
        error.value = errorMessage(e, t('firewall.wafBansLoadFailed'));
    } finally {
        loading.value = false;
    }
};

const lift = async (ban) => {
    if (!window.confirm(t('firewall.wafLiftConfirm', { ip: ban.ip }))) return;
    lifting.value = ban.ip;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await window.axios.delete(panelRoute('security.firewall.waf-bans.destroy', { ip: ban.ip }));
        notice.value = data.message;
        await load();
    } catch (e) {
        error.value = errorMessage(e, t('firewall.wafLiftFailed'));
    } finally {
        lifting.value = '';
    }
};

const until = (seconds) => new Date(seconds * 1000).toLocaleString();

onMounted(load);
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex items-center justify-between border-b border-slate-200 p-4 dark:border-slate-800">
            <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('firewall.wafBans') }}</h2>
            <button type="button" :disabled="loading" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300" @click="load">
                <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': loading }"></i> {{ t('common.refresh') }}
            </button>
        </div>
        <div v-if="error" class="mx-4 mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
        <div v-if="notice" class="mx-4 mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</div>
        <div v-if="!bans.length" class="py-10 text-center text-sm text-slate-500">{{ loading ? t('common.loading') : t('firewall.wafBansEmpty') }}</div>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-slate-400">
                    <tr>
                        <th class="px-4 py-2">{{ t('firewall.wafAddress') }}</th>
                        <th class="px-4 py-2">{{ t('firewall.wafUntil') }}</th>
                        <th class="px-4 py-2">{{ t('firewall.wafStrikes') }}</th>
                        <th class="px-4 py-2"><span class="sr-only">{{ t('firewall.wafLift') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="ban in bans" :key="ban.ip">
                        <td class="px-4 py-3 font-mono">{{ ban.ip }}</td>
                        <td class="px-4 py-3">{{ until(ban.until) }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="ban.strikes > 1 ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'">{{ ban.strikes }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" :disabled="!!lifting" class="rounded-lg px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-emerald-950" @click="lift(ban)">
                                <i class="bi bi-unlock"></i> {{ t('firewall.wafLift') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="border-t border-slate-200 p-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">{{ t('firewall.wafBansHelp') }}</p>
    </section>
</template>
