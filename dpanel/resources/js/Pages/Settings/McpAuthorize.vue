<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    requestId: { type: String, required: true },
    client: { type: Object, default: null },
    requestedScope: { type: String, default: 'read' },
    endpointUrl: { type: String, default: '' },
});

const scope = ref(props.requestedScope === 'full' ? 'full' : 'read');
const busy = ref(false);
const error = ref('');

const decide = async (approve) => {
    if (busy.value) return;
    busy.value = true;
    error.value = '';

    try {
        const { data } = await axios.post(panelRoute('mcp.oauth.decide', { requestId: props.requestId }), { approve, scope: scope.value });
        window.location.href = data.redirect;
    } catch (e) {
        error.value = e.response?.data?.message || 'Could not complete the request.';
        busy.value = false;
    }
};
</script>

<template>
    <Head title="Authorize MCP client" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-lg font-semibold">Authorize MCP client</h1>
        </template>

        <div class="mx-auto max-w-lg">
            <div v-if="!client" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                This sign-in request has expired or was already used. Start the connection again from your AI client.
            </div>

            <div v-else class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <div class="flex items-center gap-3">
                    <i class="bi bi-plug text-2xl text-blue-600"></i>
                    <div>
                        <p class="text-base font-semibold">{{ client.name }} wants to connect to this panel</p>
                        <p class="text-xs text-slate-500">Will return to <code>{{ client.redirect_host }}</code></p>
                    </div>
                </div>

                <p class="text-sm text-slate-600 dark:text-slate-300">
                    It will act as you through the MCP endpoint (<code class="text-xs">{{ endpointUrl }}</code>): read server health, websites and logs, and run commands within the access you choose. Only approve if you just started this connection yourself.
                </p>

                <div class="space-y-2">
                    <label class="flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm" :class="scope === 'read' ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/40' : 'border-slate-200 dark:border-slate-700'">
                        <input v-model="scope" type="radio" value="read" class="mt-0.5" />
                        <span><span class="font-medium">Read-only</span> — diagnose: status, logs, configuration and safe commands.</span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm" :class="scope === 'full' ? 'border-amber-500 bg-amber-50 dark:bg-amber-950/40' : 'border-slate-200 dark:border-slate-700'">
                        <input v-model="scope" type="radio" value="full" class="mt-0.5" />
                        <span><span class="font-medium">Full</span> — diagnose and fix: may also run state-changing commands and restart services. Dangerous commands are still refused, and everything is logged.</span>
                    </label>
                </div>

                <div v-if="error" class="text-sm text-red-600">{{ error }}</div>

                <div class="flex justify-end gap-2">
                    <button @click="decide(false)" :disabled="busy" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">Deny</button>
                    <button @click="decide(true)" :disabled="busy" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Allow</button>
                </div>

                <p class="text-xs text-slate-400">You can disconnect it any time under Profile menu → MCP Access.</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
