<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    panelRoute: { type: Function, required: true },
});

const state = ref(null);
const host = ref('');
const loading = ref(true);
const issuing = ref(false);
const message = ref(null);

const daysLeft = computed(() => {
    const expires = Date.parse(state.value?.expires || '');
    return Number.isNaN(expires) ? null : Math.floor((expires - Date.now()) / 86400000);
});
const healthy = computed(() => state.value?.covers_host && /Let's Encrypt/i.test(state.value?.issuer || '') && daysLeft.value > 0);

const load = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(props.panelRoute('emails.mail-hostname.tls'));
        state.value = data;
        host.value ||= data.host;
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not read the mail certificate.' };
    } finally {
        loading.value = false;
    }
};

const issue = async () => {
    issuing.value = true;
    message.value = null;
    try {
        const { data } = await axios.post(props.panelRoute('emails.mail-hostname.tls.issue'), { host: host.value.trim() });
        state.value = data;
        message.value = { type: 'success', text: data.message };
    } catch (e) {
        if (e.response?.data?.cert !== undefined) state.value = e.response.data;
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not issue the mail certificate.' };
    } finally {
        issuing.value = false;
    }
};

onMounted(load);
defineExpose({ load });
</script>

<template>
    <section id="mail-ssl" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="font-semibold">Mail SSL</h2>
        <p class="mt-1 text-sm text-slate-500">The certificate Postfix (SMTP 587/465) and Dovecot (IMAP 993) serve. It must name the mail hostname, or mail apps fail with "subjectAltName did not match". Renewed automatically every day before it expires.</p>

        <div v-if="loading" class="mt-3 text-sm text-slate-500">Checking certificate…</div>
        <dl v-else-if="state" class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
            <dt class="text-slate-500">Status</dt>
            <dd :class="healthy ? 'text-emerald-600' : 'text-amber-600'" class="font-medium">
                {{ healthy ? 'Valid for ' + state.host : (state.covers_host ? 'Self-signed or expired' : 'Does not cover ' + (state.host || 'the mail hostname')) }}
            </dd>
            <dt class="text-slate-500">Names</dt>
            <dd class="font-mono text-xs">{{ state.names.join(', ') || '—' }}</dd>
            <dt class="text-slate-500">Issuer</dt>
            <dd class="text-xs">{{ state.issuer || '—' }}</dd>
            <dt class="text-slate-500">Expires</dt>
            <dd class="text-xs">{{ state.expires || '—' }}<span v-if="daysLeft !== null" class="text-slate-500"> ({{ daysLeft }} days)</span></dd>
        </dl>

        <form class="mt-4 flex flex-wrap gap-2" @submit.prevent="issue">
            <input v-model="host" type="text" placeholder="mail.example.com" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-950">
            <button type="submit" :disabled="loading || issuing || !host.trim()" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40">
                {{ issuing ? 'Generating… (up to a minute)' : 'Generate SSL' }}
            </button>
        </form>
        <p class="mt-2 text-xs text-slate-500">The hostname needs an A record pointing to this server, set to DNS only (not proxied), and port 80 open.</p>

        <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-3 whitespace-pre-line rounded-md border px-4 py-3 text-sm">
            {{ message.text }}
        </div>
    </section>
</template>
