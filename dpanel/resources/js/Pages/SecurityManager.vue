<script setup>
import { computed, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';

const props = defineProps({ section: { type: String, default: 'overview' } });
const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params);
const live = ref({ ssh: {}, firewall: {}, ports: [] });
const loading = ref(false);
const error = ref('');
const copiedCommand = ref('');
const guideOpen = ref(false);
const guideLoading = ref(false);
const guideError = ref('');
const sshGuide = ref({ groups: [], warning: '' });
const firewallGuide = ref({ groups: [], warning: '' });
const guideType = ref('ssh');

const loadLive = async () => {
    loading.value = true;
    error.value = '';
    try {
        const response = await window.axios.get(panelRoute('security.live'), { headers: { Accept: 'application/json' } });
        live.value = response?.data?.data ?? { ssh: {}, firewall: {}, ports: [] };
    } catch (requestError) {
        error.value = requestError?.response?.data?.message ?? 'Unable to load live security status.';
    } finally {
        loading.value = false;
    }
};

const copyCommand = async (command) => {
    try {
        await navigator.clipboard.writeText(command);
        copiedCommand.value = command;
        window.setTimeout(() => { copiedCommand.value = ''; }, 1800);
    } catch {
        error.value = 'Unable to copy the command. Please copy it manually.';
    }
};

const openSshGuide = async () => {
    guideType.value = 'ssh';
    guideOpen.value = true;
    if (sshGuide.value.groups.length) return;
    guideLoading.value = true;
    guideError.value = '';
    try {
        const response = await window.axios.get(panelRoute('security.ssh.guide'), { headers: { Accept: 'application/json' } });
        sshGuide.value = response?.data?.data ?? { groups: [], warning: '' };
    } catch (requestError) {
        guideError.value = requestError?.response?.data?.message ?? 'Unable to load the SSH guide.';
    } finally {
        guideLoading.value = false;
    }
};

const openFirewallGuide = async () => {
    guideType.value = 'firewall';
    guideOpen.value = true;
    if (firewallGuide.value.groups.length) return;
    guideLoading.value = true;
    guideError.value = '';
    try {
        const response = await window.axios.get(panelRoute('security.firewall.guide'), { headers: { Accept: 'application/json' } });
        firewallGuide.value = response?.data?.data ?? { groups: [], warning: '' };
    } catch (requestError) {
        guideError.value = requestError?.response?.data?.message ?? 'Unable to load the firewall guide.';
    } finally {
        guideLoading.value = false;
    }
};

const activeGuide = computed(() => guideType.value === 'ssh' ? sshGuide.value : firewallGuide.value);
const sectionTitle = computed(() => 'Security Overview');

onMounted(loadLive);
</script>

<template>
    <Head :title="sectionTitle" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ sectionTitle }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Read-only live security monitoring through drust.</p>
            </div>
        </template>

        <div class="flex flex-col gap-5">
            <div class="order-20 flex justify-end">
                <button type="button" :disabled="loading" class="rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-60 dark:border-slate-700" @click="loadLive">{{ loading ? 'Checking...' : 'Refresh Live Data' }}</button>
            </div>
            <div v-if="error" class="order-30 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</div>

            <section class="order-first grid gap-3 sm:grid-cols-3">
                <button type="button" class="group rounded-xl border border-slate-200 bg-white p-4 text-left transition hover:border-blue-400 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-600" @click="openFirewallGuide">
                    <div class="flex items-center justify-between"><p class="text-xs text-slate-500">Firewall</p><span class="text-xs text-blue-600 group-hover:underline">View guide →</span></div>
                    <p class="mt-2 font-semibold" :class="live.firewall?.enabled ? 'text-emerald-600' : 'text-amber-600'">{{ live.firewall?.enabled ? 'Active' : 'Inactive' }}</p>
                    <p class="mt-1 text-xs text-slate-500">UFW · Read-only monitoring</p>
                </button>
                <button type="button" class="group rounded-xl border border-slate-200 bg-white p-4 text-left transition hover:border-blue-400 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-600" @click="openSshGuide">
                    <div class="flex items-center justify-between"><p class="text-xs text-slate-500">SSH</p><span class="text-xs text-blue-600 group-hover:underline">View guide →</span></div>
                    <p class="mt-2 font-semibold" :class="live.ssh?.service_active ? 'text-emerald-600' : 'text-slate-500'">{{ live.ssh?.service_active ? 'Active' : 'Inactive' }}</p>
                    <p class="mt-1 text-xs text-slate-500">Port {{ live.ssh?.port ?? '—' }} · {{ live.ssh?.listening ? 'Listening' : 'Not listening' }} · {{ live.ssh?.config_valid ? 'Config valid' : 'Config unavailable' }}</p>
                </button>
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><p class="text-xs text-slate-500">Listening ports</p><p class="mt-2 text-lg font-semibold">{{ (live.ports || []).filter(item => item.listening).length }}</p></div>
            </section>

            <section class="order-40 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">Live Ports</h2>
                <div class="mt-4 overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-700">
                    <thead class="text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">Port</th><th class="px-3 py-2">Service</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Bind address</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800"><tr v-for="item in live.ports || []" :key="`${item.port}-${item.protocol}`">
                        <td class="px-3 py-3 font-mono font-semibold">{{ item.port }}/{{ item.protocol }}</td><td class="px-3 py-3"><p>{{ item.service }}</p><p class="text-xs text-slate-500">{{ item.processes?.join(', ') }}</p></td>
                        <td class="px-3 py-3"><span class="mr-1 rounded-full px-2 py-1 text-xs" :class="item.listening ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ item.listening ? 'Listening' : 'Not listening' }}</span><span class="rounded-full px-2 py-1 text-xs" :class="item.firewall_allowed ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700'">{{ item.firewall_allowed ? 'UFW allowed' : 'UFW blocked' }}</span></td>
                        <td class="px-3 py-3 font-mono text-xs">{{ item.addresses?.join(', ') || '—' }}</td>
                    </tr></tbody>
                </table></div>
            </section>

            <div v-if="guideOpen" class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="SSH management guide">
                <button type="button" class="absolute inset-0 bg-slate-950/50 backdrop-blur-[1px]" aria-label="Close SSH guide" @click="guideOpen = false"></button>
                <aside class="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col bg-white shadow-2xl dark:bg-slate-900">
                    <div class="flex items-start justify-between border-b border-slate-200 p-5 dark:border-slate-800">
                        <div><h2 class="font-semibold">{{ guideType === 'ssh' ? 'SSH Status & Guide' : 'Firewall Status & Guide' }}</h2><p class="mt-1 text-xs text-slate-500">Live status with simple server commands</p></div>
                        <button type="button" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Close" @click="guideOpen = false">✕</button>
                    </div>
                    <div v-if="guideType === 'ssh'" class="grid grid-cols-3 gap-2 border-b border-slate-200 p-4 dark:border-slate-800"><div v-for="item in [['Service', live.ssh?.service_active ? 'Active' : 'Inactive'], ['Port', live.ssh?.port ?? '—'], ['Listening', live.ssh?.listening ? 'Yes' : 'No']]" :key="item[0]" class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><p class="text-xs text-slate-500">{{ item[0] }}</p><p class="mt-1 text-sm font-semibold">{{ item[1] }}</p></div></div>
                    <div v-else class="grid grid-cols-2 gap-2 border-b border-slate-200 p-4 dark:border-slate-800"><div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><p class="text-xs text-slate-500">UFW service</p><p class="mt-1 text-sm font-semibold" :class="live.firewall?.enabled ? 'text-emerald-600' : 'text-amber-600'">{{ live.firewall?.enabled ? 'Active' : 'Inactive' }}</p></div><div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><p class="text-xs text-slate-500">Mode</p><p class="mt-1 text-sm font-semibold">Read-only</p></div></div>
                    <div class="flex-1 overflow-y-auto p-5">
                        <p v-if="guideLoading" class="py-12 text-center text-sm text-slate-500">Loading guide...</p>
                        <div v-else-if="guideError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ guideError }}</div>
                        <div v-else class="space-y-5"><div v-for="group in activeGuide.groups" :key="group.title"><h3 class="text-sm font-semibold">{{ group.title }}</h3><div class="mt-2 space-y-2"><div v-for="command in group.commands" :key="command" class="flex items-center gap-2 rounded-lg bg-slate-950 p-2 text-slate-100"><code class="min-w-0 flex-1 overflow-x-auto whitespace-nowrap px-1 text-xs">{{ command }}</code><button type="button" class="rounded bg-slate-700 px-2 py-1 text-xs hover:bg-slate-600" @click="copyCommand(command)">{{ copiedCommand === command ? 'Copied' : 'Copy' }}</button></div></div></div><div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ activeGuide.warning }}</div></div>
                    </div>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
