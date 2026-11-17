<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AccessGroups from './McpAccess/Partials/AccessGroups.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    endpointUrl: { type: String, default: '' },
    enabled: { type: Boolean, default: true },
    oauthEnabled: { type: Boolean, default: true },
    localAccess: { type: String, default: 'off' },
    tools: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
});

const groupList = ref([...props.groups]);
const bumpGroupCount = (groupId, delta) => {
    const g = groupList.value.find((x) => x.id === groupId);
    if (g) g.tokens_count = Math.max(0, (g.tokens_count || 0) + delta);
};

const tokenList = ref([...props.tokens]);
const toggleForm = useForm({});
const deleteForm = useForm({});

const form = ref({ name: '', scope: 'read', access_group_id: '', expires_in_days: '' });
const creating = ref(false);
const createError = ref('');
const newToken = ref(null);
const copiedKey = ref(null);
const client = ref(props.oauthEnabled ? 'claude-ai' : 'claude-code');

const shownToken = computed(() => newToken.value || 'dp-mcp-YOUR_TOKEN');

const snippets = computed(() => ({
    'claude-ai': `Name: dPanel\nRemote MCP server URL: ${props.endpointUrl}`,
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

const clients = computed(() => [
    ...(props.oauthEnabled ? [{ id: 'claude-ai', label: 'claude.ai / Claude apps', hint: t('settings.mcpAccess.hints.claudeAi') }] : []),
    { id: 'claude-code', label: 'Claude Code', hint: props.oauthEnabled ? t('settings.mcpAccess.hints.claudeCodeOauth') : t('settings.mcpAccess.hints.claudeCode') },
    { id: 'claude-desktop', label: 'Claude Desktop', hint: t('settings.mcpAccess.hints.claudeDesktop') },
    { id: 'cursor', label: 'Cursor', hint: t('settings.mcpAccess.hints.cursor') },
    { id: 'vscode', label: 'VS Code', hint: t('settings.mcpAccess.hints.vscode') },
]);

const create = async () => {
    if (!form.value.name.trim() || creating.value) return;

    creating.value = true;
    createError.value = '';

    try {
        const { data } = await axios.post(panelRoute('mcp.tokens.store'), {
            name: form.value.name,
            scope: form.value.scope,
            access_group_id: form.value.access_group_id || null,
            expires_in_days: form.value.expires_in_days || null,
        });
        tokenList.value.unshift(data.token);
        if (data.token.access_group) bumpGroupCount(data.token.access_group.id, 1);
        newToken.value = data.plain_token;
        form.value = { name: '', scope: 'read', access_group_id: '', expires_in_days: '' };
    } catch (e) {
        const errors = e.response?.data?.errors || {};
        createError.value = Object.values(errors)[0]?.[0] || e.response?.data?.message || t('settings.mcpAccess.createFailed');
    } finally {
        creating.value = false;
    }
};

const toggle = (token) => toggleForm.patch(panelRoute('mcp.tokens.toggle', { mcpToken: token.id }), {
    preserveScroll: true,
    onSuccess: () => { token.is_active = !token.is_active; },
});

const remove = (token) => {
    if (!confirm(t('settings.mcpAccess.revokeConfirm', { name: token.name }))) return;
    deleteForm.delete(panelRoute('mcp.tokens.destroy', { mcpToken: token.id }), {
        preserveScroll: true,
        onSuccess: () => {
            tokenList.value = tokenList.value.filter((x) => x.id !== token.id);
            if (token.access_group) bumpGroupCount(token.access_group.id, -1);
        },
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
    if (!iso) return t('settings.mcpAccess.never');
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
};
</script>

<template>
    <Head :title="t('settings.mcpAccess.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('settings.mcpAccess.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('settings.mcpAccess.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="!enabled" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                <i18n-t keypath="settings.mcpAccess.disabled" scope="global"><template #env><code>DPANEL_MCP_ENABLED=false</code></template></i18n-t>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <span class="font-medium">{{ t('settings.mcpAccess.localTitle') }}</span>
                    <span class="ml-1 rounded px-2 py-0.5 text-xs" :class="{
                        'bg-slate-100 text-slate-500': localAccess === 'off',
                        'bg-sky-100 text-sky-700': localAccess === 'read',
                        'bg-amber-100 text-amber-700': localAccess === 'full',
                        'bg-slate-100 text-slate-600': localAccess === 'unknown',
                    }">{{ { off: t('settings.mcpAccess.local.off'), read: t('settings.mcpAccess.readOnly'), full: t('settings.mcpAccess.full'), unknown: t('settings.mcpAccess.local.unknown') }[localAccess] || localAccess }}</span>
                    <p class="mt-1 text-xs text-slate-500">{{ t('settings.mcpAccess.localText') }}</p>
                </div>
                <code class="rounded bg-slate-100 px-2 py-1 text-xs dark:bg-slate-900">sudo dpanel mcp local {{ localAccess === 'off' ? 'on read' : 'off' }}</code>
            </div>

            <div v-if="newToken" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm dark:border-emerald-800 dark:bg-emerald-950">
                <p class="font-medium text-emerald-800 dark:text-emerald-300">{{ t('settings.mcpAccess.copyNow') }}</p>
                <div class="mt-2 flex items-center gap-2">
                    <code class="flex-1 select-all break-all rounded-md border border-emerald-300 bg-white px-3 py-2 text-xs dark:border-emerald-700 dark:bg-slate-900">{{ newToken }}</code>
                    <button @click="copy('token', newToken)" class="shrink-0 rounded-md border border-emerald-300 px-3 py-2 text-xs hover:bg-emerald-100 dark:border-emerald-700 dark:hover:bg-emerald-900">{{ copiedKey === 'token' ? t('settings.mcpAccess.copied') : t('settings.mcpAccess.copy') }}</button>
                </div>
            </div>

            <form @submit.prevent="create" class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_auto_auto_auto_auto] sm:items-end dark:border-slate-700 dark:bg-slate-800">
                <div>
                    <label class="mb-1 block text-xs font-medium">{{ t('settings.mcpAccess.tokenName') }}</label>
                    <input v-model="form.name" type="text" :placeholder="t('settings.mcpAccess.tokenNamePlaceholder')"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">{{ t('settings.mcpAccess.access') }}</label>
                    <select v-model="form.scope" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="read">{{ t('settings.mcpAccess.readOption') }}</option>
                        <option value="full">{{ t('settings.mcpAccess.fullOption') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">{{ t('settings.mcpAccess.tools') }}</label>
                    <select v-model="form.access_group_id" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">{{ t('settings.mcpAccess.allTools') }}</option>
                        <option v-for="g in groupList" :key="g.id" :value="g.id">{{ g.name }} ({{ g.tools.length }})</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">{{ t('settings.mcpAccess.expiresAfter') }}</label>
                    <input v-model="form.expires_in_days" type="number" min="1" :placeholder="t('settings.mcpAccess.never')"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm sm:w-32 dark:border-slate-600 dark:bg-slate-900" />
                </div>
                <button type="submit" :disabled="creating"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">{{ t('settings.mcpAccess.create') }}</button>
                <div v-if="createError" class="text-xs text-red-600 sm:col-span-5">{{ createError }}</div>
                <p v-if="form.scope === 'full'" class="text-xs text-amber-600 sm:col-span-5">
                    {{ t('settings.mcpAccess.fullWarning') }}
                </p>
            </form>

            <AccessGroups v-model:groups="groupList" :tools="tools" :panel-route="panelRoute" />

            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('settings.mcpAccess.cols.name') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('settings.mcpAccess.cols.token') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('settings.mcpAccess.access') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('settings.mcpAccess.tools') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('settings.mcpAccess.cols.status') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('settings.mcpAccess.cols.owner') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('settings.mcpAccess.cols.lastUsed') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('settings.mcpAccess.cols.expires') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('settings.mcpAccess.cols.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="!tokenList.length">
                            <td colspan="9" class="px-4 py-6 text-center text-sm text-slate-400">{{ t('settings.mcpAccess.empty') }}</td>
                        </tr>
                        <tr v-for="tok in tokenList" :key="tok.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3 font-medium">
                                {{ tok.name }}
                                <span v-if="tok.via_oauth" class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-normal text-violet-700" :title="t('settings.mcpAccess.oauthTitle')">OAuth</span>
                            </td>
                            <td class="px-4 py-3"><code class="text-xs text-slate-400">{{ tok.token_prefix }}</code></td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs" :class="tok.scope === 'full' ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700'">{{ tok.scope === 'full' ? t('settings.mcpAccess.full') : t('settings.mcpAccess.readOnly') }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span v-if="tok.access_group" class="rounded bg-indigo-100 px-2 py-0.5 text-indigo-700">{{ tok.access_group.name }}</span>
                                <span v-else class="text-slate-400">{{ t('settings.mcpAccess.allTools') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="tok.expired" class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">{{ t('settings.mcpAccess.expired') }}</span>
                                <span v-else class="rounded px-2 py-0.5 text-xs" :class="tok.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ tok.is_active ? t('settings.mcpAccess.active') : t('settings.mcpAccess.disabledLabel') }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ tok.owner }}</td>
                            <td class="px-4 py-3 text-slate-500">
                                {{ fmtDate(tok.last_used_at) }}
                                <div v-if="tok.last_used_ip" class="text-xs text-slate-400">{{ tok.last_used_ip }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ tok.expires_at ? fmtDate(tok.expires_at) : t('settings.mcpAccess.never') }}</td>
                            <td class="space-x-1 whitespace-nowrap px-4 py-3 text-right">
                                <button @click="toggle(tok)" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600">{{ tok.is_active ? t('settings.mcpAccess.disable') : t('settings.mcpAccess.enable') }}</button>
                                <button @click="remove(tok)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">{{ t('settings.mcpAccess.revoke') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">{{ t('settings.mcpAccess.connect') }}</h2>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        {{ t('settings.mcpAccess.endpoint') }}
                        <code class="rounded bg-slate-100 px-1.5 py-0.5 dark:bg-slate-900">{{ endpointUrl }}</code>
                        <button @click="copy('url', endpointUrl)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" :title="t('settings.mcpAccess.copyEndpoint')">
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
                    <button @click="copy('snippet', snippets[client])" class="absolute right-2 top-2 rounded border border-slate-600 px-2 py-0.5 text-xs text-slate-200 hover:bg-slate-700">{{ copiedKey === 'snippet' ? t('settings.mcpAccess.copied') : t('settings.mcpAccess.copy') }}</button>
                </div>
                <p v-if="!newToken" class="mt-2 text-xs text-slate-400">{{ t('settings.mcpAccess.snippetHint') }}</p>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">{{ t('settings.mcpAccess.available') }}</h2>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <span v-for="tool in tools" :key="tool.name" class="rounded border px-2 py-0.5 text-xs"
                        :class="tool.read_only ? 'border-slate-200 text-slate-600 dark:border-slate-600 dark:text-slate-300' : 'border-amber-300 text-amber-700 dark:border-amber-700 dark:text-amber-400'"
                        :title="tool.title">{{ tool.name }}</span>
                </div>
                <p class="mt-2 text-xs text-slate-400">{{ t('settings.mcpAccess.amberHint') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
