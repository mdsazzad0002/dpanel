<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    panelRoute: { type: Function, required: true },
});

const emit = defineEmits(['changed']);

const state = ref(null);
const loading = ref(true);
const saving = ref('');
const message = ref(null);

const load = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(props.panelRoute('emails.mail-hostname.show'));
        state.value = data;
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not load mail hostname.' };
    } finally {
        loading.value = false;
    }
};

// An empty host means "detect and use the best candidate".
const apply = async (host = '') => {
    if (host && !confirm(`Use ${host} as the mail hostname?\n\nEvery domain's MX record must then be changed to ${host}; until you do, Verify DNS shows MX as wrong.`)) return;
    saving.value = host || 'best';
    message.value = null;
    try {
        const { data } = await axios.post(props.panelRoute('emails.mail-hostname.update'), { host });
        state.value = data;
        message.value = { type: 'success', text: data.message };
        emit('changed');
    } catch (e) {
        if (e.response?.data?.candidates) state.value = e.response.data;
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not set the mail hostname.' };
    } finally {
        saving.value = '';
    }
};

onMounted(load);
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">Mail hostname</h2>
                <p class="mt-1 text-sm text-slate-500">The name this server announces when sending mail, and the MX target for every domain. It must resolve to this server. Updates pick it automatically.</p>
                <p class="mt-2 text-sm">Current: <code class="font-semibold">{{ state?.current || '—' }}</code></p>
            </div>
            <button type="button" :disabled="loading || !!saving || !state?.best" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="apply()">
                {{ saving === 'best' ? 'Applying…' : 'Detect & apply best' }}
            </button>
        </div>

        <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-3 rounded-md border px-4 py-3 text-sm">
            {{ message.text }}
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr><th class="px-3 py-2">Candidate</th><th class="px-3 py-2">From</th><th class="px-3 py-2">Resolves to</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody>
                    <tr v-for="candidate in state?.candidates || []" :key="candidate.host" class="border-t border-slate-200 dark:border-slate-800">
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ candidate.host }}
                            <span v-if="candidate.host === state.current" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold dark:bg-slate-800">current</span>
                            <span v-if="candidate.host === state.best" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">best</span>
                        </td>
                        <td class="px-3 py-2 text-xs text-slate-500">{{ candidate.source }}</td>
                        <td v-if="candidate.lookup_failed" class="px-3 py-2 text-xs text-amber-600">DNS did not answer; refresh to check again</td>
                        <td v-else class="px-3 py-2 font-mono text-xs" :class="candidate.usable ? 'text-emerald-600' : 'text-red-600'">
                            {{ candidate.addresses.join(', ') || 'does not resolve' }}
                            <span v-if="!candidate.usable" class="font-sans">(not this server)</span>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <button v-if="candidate.usable && candidate.host !== state.current" type="button" :disabled="!!saving" class="rounded border border-blue-300 px-2.5 py-1 text-xs text-blue-700 hover:bg-blue-50 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20" @click="apply(candidate.host)">
                                {{ saving === candidate.host ? 'Applying…' : 'Use this' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!loading && !(state?.candidates || []).length">
                        <td colspan="4" class="px-3 py-3 text-center text-slate-500">No candidates. Add an A record (DNS only) such as mail.&lt;panel domain&gt; pointing to this server.</td>
                    </tr>
                    <tr v-if="loading"><td colspan="4" class="px-3 py-3 text-center text-slate-500">Checking DNS…</td></tr>
                </tbody>
            </table>
        </div>
        <p v-if="state && !state.best" class="mt-3 text-sm text-amber-700 dark:text-amber-300">No candidate resolves to this server yet. Add an A record (DNS only, not proxied) for mail.&lt;panel domain&gt; pointing here, then apply.</p>
    </section>
</template>
