<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    endpointUrl: { type: String, default: '' },
    enabled: { type: Boolean, default: true },
    tools: { type: Array, default: () => [] },
});

const tokenList = ref([...props.tokens]);
const toggleForm = useForm({});
const deleteForm = useForm({});

const form = ref({ name: '', scope: 'read', expires_in_days: '' });
const creating = ref(false);
const createError = ref('');
const newToken = ref(null);
const copiedKey = ref(null);
const client = ref('claude-code');

const shownToken = computed(() => newToken.value || 'dp-mcp-YOUR_TOKEN');

const snippets = computed(() => ({
    'claude-code': `claude mcp add --transport http dpanel ${props.endpointUrl} \\\n  --header "Authorization: Bearer ${shownToken.value}"`,
    'claude-desktop': JSON.stringify({
        mcpServers: {
            dpanel: {
                command: 'npx',
                args: ['-y', 'mcp-remote', props.endpointUrl, '--header', 'Authorization:${DPANEL_MCP_AUTH}'],
                env: { DPANEL_MCP_AUTH: `Bearer ${shownToken.value}` },
            },
        },
    }, null, 2),
    cursor: JSON.stringify({
        mcpServers: {
            dpanel: {
                url: props.endpointUrl,
                headers: { Authorization: `Bearer ${shownToken.value}` },
            },
        },
    }, null, 2),
    vscode: JSON.stringify({
        servers: {
            dpanel: {
                type: 'http',
                url: props.endpointUrl,
                headers: { Authorization: `Bearer ${shownToken.value}` },
            },
        },
    }, null, 2),
}));

const clients = [
    { id: 'claude-code', label: 'Claude Code', hint: 'Run in your terminal.' },
    { id: 'claude-desktop', label: 'Claude Desktop', hint: 'Add to claude_desktop_config.json (needs Node.js for mcp-remote).' },
    { id: 'cursor', label: 'Cursor', hint: 'Add to ~/.cursor/mcp.json.' },
    { id: 'vscode', label: 'VS Code', hint: 'Add to .vscode/mcp.json.' },
];

const create = async () => {
    if (!form.value.name.trim() || creating.value) return;

    creating.value = true;
    createError.value = '';

    try {
        const { data } = await axios.post(panelRoute('mcp.tokens.store'), {
            name: form.value.name,
            scope: form.value.scope,
            expires_in_days: form.value.expires_in_days || null,
        });
        tokenList.value.unshift(data.token);
        newToken.value = data.plain_token;
        form.value = { name: '', scope: 'read', expires_in_days: '' };
    } catch (e) {
        const errors = e.response?.data?.errors || {};
        createError.value = Object.values(errors)[0]?.[0] || e.response?.data?.message || 'Could not create the token.';
    } finally {
        creating.value = false;
    }
};

const toggle = (t) => toggleForm.patch(panelRoute('mcp.tokens.toggle', { mcpToken: t.id }), {
    preserveScroll: true,
    onSuccess: () => { t.is_active = !t.is_active; },
});

const remove = (t) => {
    if (!confirm(`Revoke MCP token "${t.name}"? Clients using it lose access immediately.`)) return;
    deleteForm.delete(panelRoute('mcp.tokens.destroy', { mcpToken: t.id }), {
        preserveScroll: true,
        onSuccess: () => { tokenList.value = tokenList.value.filter((x) => x.id !== t.id); },
    });
};

const copy = async (key, text) => {
    try {
        await navigator.clipboard.writeText(text);
        copiedKey.value = key;
        setTimeout(() => { copiedKey.value = null; }, 2000);
    } catch (e) {
        // Clipboard API unavailable — the text is still selectable.
    }
};

const fmtDate = (iso) => {
    if (!iso) return 'Never';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
};
</script>

<template>
    <Head title="MCP Access" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">MCP Access</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Connect Claude, Cursor or any MCP client to this panel, so it can diagnose and fix server problems from anywhere.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="!enabled" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                The MCP endpoint is turned off (<code>DPANEL_MCP_ENABLED=false</code>). Tokens can be issued, but clients can't connect until it's enabled.
            </div>

            <div v-if="newToken" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm dark:border-emerald-800 dark:bg-emerald-950">
                <p class="font-medium text-emerald-800 dark:text-emerald-300">Copy this token now — it won't be shown again. The setup snippets below already include it.</p>
                <div class="mt-2 flex items-center gap-2">
                    <code class="flex-1 select-all break-all rounded-md border border-emerald-300 bg-white px-3 py-2 text-xs dark:border-emerald-700 dark:bg-slate-900">{{ newToken }}</code>
                    <button @click="copy('token', newToken)" class="shrink-0 rounded-md border border-emerald-300 px-3 py-2 text-xs hover:bg-emerald-100 dark:border-emerald-700 dark:hover:bg-emerald-900">{{ copiedKey === 'token' ? 'Copied!' : 'Copy' }}</button>
                </div>
            </div>

            <form @submit.prevent="create" class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <label class="mb-1 block text-xs font-medium">Token name</label>
                    <input v-model="form.name" type="text" placeholder="e.g. My laptop – Claude Code"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Access</label>
                    <select v-model="form.scope" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="read">Read-only (diagnose)</option>
                        <option value="full">Full (diagnose &amp; fix)</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Expires after (days)</label>
                    <input v-model="form.expires_in_days" type="number" min="1" placeholder="Never"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm sm:w-32 dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <button type="submit" :disabled="creating"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Create token</button>
                <div v-if="createError" class="text-xs text-red-600 sm:col-span-4">{{ createError }}</div>
                <p v-if="form.scope === 'full'" class="text-xs text-amber-600 sm:col-span-4">
                    Full access lets the AI client run state-changing commands (install packages, restart services, edit files) on your servers as their SSH user. Dangerous commands are still refused and every command is logged.
                </p>
            </form>

            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Token</th>
                            <th class="px-4 py-3 font-medium">Access</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Owner</th>
                            <th class="px-4 py-3 font-medium">Last used</th>
                            <th class="px-4 py-3 font-medium">Expires</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="!tokenList.length">
                            <td colspan="8" class="px-4 py-6 text-center text-sm text-slate-400">No MCP tokens yet.</td>
                        </tr>
                        <tr v-for="t in tokenList" :key="t.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3 font-medium">{{ t.name }}</td>
                            <td class="px-4 py-3"><code class="text-xs text-slate-400">{{ t.token_prefix }}</code></td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs" :class="t.scope === 'full' ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700'">{{ t.scope === 'full' ? 'Full' : 'Read-only' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="t.expired" class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">Expired</span>
                                <span v-else class="rounded px-2 py-0.5 text-xs" :class="t.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ t.is_active ? 'Active' : 'Disabled' }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ t.owner }}</td>
                            <td class="px-4 py-3 text-slate-500">
                                {{ fmtDate(t.last_used_at) }}
                                <div v-if="t.last_used_ip" class="text-xs text-slate-400">{{ t.last_used_ip }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ t.expires_at ? fmtDate(t.expires_at) : 'Never' }}</td>
                            <td class="space-x-1 whitespace-nowrap px-4 py-3 text-right">
                                <button @click="toggle(t)" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600">{{ t.is_active ? 'Disable' : 'Enable' }}</button>
                                <button @click="remove(t)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">Revoke</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">Connect a client</h2>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        Endpoint
                        <code class="rounded bg-slate-100 px-1.5 py-0.5 dark:bg-slate-900">{{ endpointUrl }}</code>
                        <button @click="copy('url', endpointUrl)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" title="Copy endpoint">
                            <i class="bi" :class="copiedKey === 'url' ? 'bi-check2' : 'bi-clipboard'"></i>
                        </button>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-1">
                    <button v-for="c in clients" :key="c.id" @click="client = c.id"
                        class="rounded-md border px-3 py-1 text-xs"
                        :class="client === c.id ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700'">{{ c.label }}</button>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ clients.find((c) => c.id === client)?.hint }}</p>
                <div class="relative mt-2">
                    <pre class="overflow-x-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100">{{ snippets[client] }}</pre>
                    <button @click="copy('snippet', snippets[client])" class="absolute right-2 top-2 rounded border border-slate-600 px-2 py-0.5 text-xs text-slate-200 hover:bg-slate-700">{{ copiedKey === 'snippet' ? 'Copied!' : 'Copy' }}</button>
                </div>
                <p v-if="!newToken" class="mt-2 text-xs text-slate-400">Create a token above and the snippets fill it in for you.</p>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Available tools</h2>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <span v-for="tool in tools" :key="tool.name" class="rounded border px-2 py-0.5 text-xs"
                        :class="tool.read_only ? 'border-slate-200 text-slate-600 dark:border-slate-600 dark:text-slate-300' : 'border-amber-300 text-amber-700 dark:border-amber-700 dark:text-amber-400'"
                        :title="tool.title">{{ tool.name }}</span>
                </div>
                <p class="mt-2 text-xs text-slate-400">Amber tools can change server state; with a read-only token they only accept diagnostic commands.</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
