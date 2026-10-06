<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    panelRoute: { type: Function, required: true },
});

const state = ref(null);
const loading = ref(true);
const busy = ref('');
const message = ref(null);
const hostnames = reactive({});
const newIp = reactive({ ip: '', hostname: '' });

const daysLeft = (expires) => {
    const time = Date.parse(expires || '');
    return Number.isNaN(time) ? null : Math.floor((time - Date.now()) / 86400000);
};
const certState = (row) => {
    const cert = row.certificate;
    if (!row.hostname) return { ok: false, text: 'Set a hostname first' };
    if (!cert?.issuer) return { ok: false, text: 'No certificate yet' };
    if (!cert.covers_host) return { ok: false, text: 'Does not cover ' + row.hostname };
    if (!/Let's Encrypt/i.test(cert.issuer)) return { ok: false, text: 'Self-signed' };
    const days = daysLeft(cert.expires);
    return days > 0 ? { ok: true, text: `Valid, ${days} days left` } : { ok: false, text: 'Expired' };
};

const apply = (data) => {
    state.value = data;
    for (const row of data.ips || []) hostnames[row.id] = row.hostname;
    if (!newIp.ip && data.addable?.length) newIp.ip = data.addable[0];
};

const call = async (key, method, routeName, params, payload) => {
    busy.value = key;
    message.value = null;
    try {
        const { data } = await axios({ method, url: props.panelRoute(routeName, params), data: payload });
        apply(data);
        message.value = { type: 'success', text: data.message };
        return true;
    } catch (e) {
        if (e.response?.data?.ips) apply(e.response.data);
        message.value = { type: 'error', text: e.response?.data?.message || 'Request failed.' };
        return false;
    } finally {
        busy.value = '';
    }
};

const load = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(props.panelRoute('emails.mail-ips'));
        apply(data);
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not load mail IPs.' };
    } finally {
        loading.value = false;
    }
};

const saveHostname = (row) => call(`host-${row.id}`, 'patch', 'emails.mail-ips.update', { id: row.id }, { hostname: hostnames[row.id] });
const removeIp = (row) => confirm(`Remove ${row.ip}? Its domains move to the default IP and need their MX and SPF updated.`)
    && call(`remove-${row.id}`, 'delete', 'emails.mail-ips.destroy', { id: row.id });
const addIp = async () => {
    if (await call('add', 'post', 'emails.mail-ips.store', {}, { ...newIp })) newIp.hostname = '';
};
const assign = (domain, mailIpId) => call(`assign-${domain}`, 'post', 'emails.mail-ips.assign', {}, { domain, mail_ip_id: mailIpId || null });
const generateSsl = () => call('ssl', 'post', 'emails.mail-ips.ssl', {}, {});

onMounted(load);
defineExpose({ load });
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">Mail IPs &amp; SSL</h2>
                <p class="mt-1 max-w-3xl text-sm text-slate-500">Each IP has one hostname: its HELO name, the MX of its domains, and the name its PTR must give. Each hostname gets its own certificate for SMTP (587/465) and IMAP (993). Certificates renew automatically every day.</p>
            </div>
            <button type="button" :disabled="loading || !!busy || !state?.ips?.some((row) => row.hostname)" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="generateSsl">
                {{ busy === 'ssl' ? 'Generating… (up to a minute per hostname)' : 'Generate SSL' }}
            </button>
        </div>

        <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-3 whitespace-pre-line rounded-md border px-4 py-3 text-sm">
            {{ message.text }}
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr><th class="px-3 py-2">IP</th><th class="px-3 py-2">Hostname</th><th class="px-3 py-2">PTR</th><th class="px-3 py-2">SSL</th><th class="px-3 py-2">Domains</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody>
                    <tr v-if="loading"><td colspan="6" class="px-3 py-3 text-center text-slate-500">Checking IPs and certificates…</td></tr>
                    <tr v-for="row in state?.ips || []" :key="row.id" class="border-t border-slate-200 align-top dark:border-slate-800">
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ row.ip }}
                            <span v-if="row.is_default" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 font-sans text-[10px] font-semibold dark:bg-slate-800">default</span>
                        </td>
                        <td class="px-3 py-2">
                            <form class="flex gap-1" @submit.prevent="saveHostname(row)">
                                <input v-model="hostnames[row.id]" type="text" placeholder="mail.example.com" class="w-52 rounded border border-slate-300 px-2 py-1 font-mono text-xs dark:border-slate-700 dark:bg-slate-950">
                                <button v-if="hostnames[row.id] !== row.hostname" type="submit" :disabled="!!busy" class="rounded border border-blue-300 px-2 py-1 text-xs text-blue-700 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300">
                                    {{ busy === `host-${row.id}` ? 'Saving…' : 'Save' }}
                                </button>
                            </form>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs" :class="row.ptr && row.ptr === row.hostname ? 'text-emerald-600' : 'text-amber-600'">
                            {{ row.ptr || 'none' }}
                            <div v-if="row.hostname && row.ptr !== row.hostname" class="font-sans text-[11px] text-slate-500">Ask your provider to set it to {{ row.hostname }}</div>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            <span :class="certState(row).ok ? 'text-emerald-600' : 'text-amber-600'" class="font-medium">{{ certState(row).text }}</span>
                            <div v-if="row.certificate?.expires" class="text-slate-500">{{ row.certificate.expires }}</div>
                        </td>
                        <td class="px-3 py-2 text-xs text-slate-500">{{ row.domains.length ? row.domains.join(', ') : '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            <button v-if="!row.is_default" type="button" :disabled="!!busy" class="rounded border border-red-300 px-2 py-1 text-xs text-red-700 disabled:opacity-60 dark:border-red-800 dark:text-red-300" @click="removeIp(row)">
                                {{ busy === `remove-${row.id}` ? 'Removing…' : 'Remove' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <form v-if="state" class="mt-4 flex flex-wrap items-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-800" @submit.prevent="addIp">
            <div>
                <label class="mb-1 block text-xs text-slate-500">Add IP</label>
                <select v-if="state.addable.length" v-model="newIp.ip" class="rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-950">
                    <option v-for="ip in state.addable" :key="ip" :value="ip">{{ ip }}</option>
                </select>
                <p v-else class="py-2 text-sm text-slate-500">No other public IP on this server's interfaces. Add the IP to the server first (provider panel + netplan).</p>
            </div>
            <template v-if="state.addable.length">
                <div class="min-w-0 flex-1">
                    <label class="mb-1 block text-xs text-slate-500">Hostname (A record → this IP, DNS only)</label>
                    <input v-model="newIp.hostname" type="text" placeholder="mail2.example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-950">
                </div>
                <button type="submit" :disabled="!!busy || !newIp.hostname.trim()" class="rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-700 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300">
                    {{ busy === 'add' ? 'Adding…' : 'Add IP' }}
                </button>
            </template>
        </form>

        <div v-if="state?.ips?.length > 1 && state.domains.length" class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-800">
            <h3 class="text-sm font-semibold">Sending IP per domain</h3>
            <p class="mt-1 text-xs text-slate-500">After changing a domain's IP, update its MX and SPF from the Mail DNS Guide.</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label v-for="row in state.domains" :key="row.domain" class="flex items-center justify-between gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                    <span class="truncate font-mono text-xs">{{ row.domain }}</span>
                    <select :value="row.mail_ip_id || ''" :disabled="!!busy" class="rounded border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-950" @change="assign(row.domain, $event.target.value)">
                        <option v-for="ipRow in state.ips" :key="ipRow.id" :value="ipRow.is_default ? '' : ipRow.id">{{ ipRow.ip }}{{ ipRow.is_default ? ' (default)' : '' }}</option>
                    </select>
                </label>
            </div>
        </div>
    </section>
</template>
