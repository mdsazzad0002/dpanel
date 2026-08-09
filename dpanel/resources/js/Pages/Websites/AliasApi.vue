<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const props = defineProps({ website: { type: Object, required: true }, aliases: { type: Array, default: () => [] }, aliasApi: { type: Object, required: true } });
const page = usePage(); const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);
const state = ref({ ...props.aliasApi }); const plainToken = ref(''); const loading = ref(false);
const aliasRows = ref([...props.aliases]);
const aliasDomain = ref('');
const aliasActionId = ref('');
const editingAliasId = ref('');
const editingAliasDomain = ref('');
const result = ref(null);
const resultType = ref('success');
const resultMessage = ref('');
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const rotate = async () => { if (!confirm('Rotate token? The previous token will stop working.')) return; loading.value = true; try { const r = await fetch(panelRoute('websites.alias-api.rotate', { id: props.website.id }), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } }); const d = await r.json(); if (r.ok) { plainToken.value = d.token; state.value = { ...state.value, enabled: true, has_token: true, token_hint: d.token.slice(-8), challenge_token: d.challenge_token }; } } finally { loading.value = false; } };
const toggle = async () => { loading.value = true; try { const r = await fetch(panelRoute('websites.alias-api.toggle', { id: props.website.id }), { method: 'PATCH', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ enabled: !state.value.enabled }) }); if (r.ok) state.value.enabled = !state.value.enabled; } finally { loading.value = false; } };
const showResult = (data, type = 'success', fallback = '') => { result.value = data || {}; resultType.value = type; resultMessage.value = data?.message || fallback; };
const createAlias = async () => {
    const domain = aliasDomain.value.trim().toLowerCase();
    if (!domain || aliasActionId.value) return;
    aliasActionId.value = 'create';
    try {
        const response = await window.axios.post(panelRoute('websites.store'), {
            domain_type: 'alis', domain, parent_id: props.website.id, parent_domain: props.website.domain,
            root_path: '', start_directory: props.website.start_directory ?? '', php_version: null,
            enable_ssl: false, manage_dns: false, assigned_user_id: null,
        });
        const row = response.data?.website;
        if (row?.id) aliasRows.value.unshift({ ...row, ssl_status: response.data?.ssl?.status || (row.enable_ssl ? 'enabled' : 'disabled'), ssl_expires_at: response.data?.ssl?.expires_at || null });
        aliasDomain.value = '';
        showResult(response.data, 'success', 'Alias created successfully.');
    } catch (error) {
        const data = error?.response?.data || {};
        if (data?.website?.id && !aliasRows.value.some((item) => String(item.id) === String(data.website.id))) {
            aliasRows.value.unshift({ ...data.website, ssl_status: data?.ssl?.status || 'failed' });
        }
        showResult(data, 'error', 'Alias creation failed.');
    } finally { aliasActionId.value = ''; }
};
const beginAliasEdit = (alias) => { editingAliasId.value = String(alias.id); editingAliasDomain.value = String(alias.domain || ''); };
const saveAliasEdit = async (alias) => {
    const domain = editingAliasDomain.value.trim().toLowerCase();
    if (!domain || aliasActionId.value) return;
    aliasActionId.value = String(alias.id);
    try {
        const response = await window.axios.patch(panelRoute('websites.alias.update', { id: alias.id }), { domain });
        Object.assign(alias, response.data?.website || {}, { domain }); editingAliasId.value = '';
        showResult(response.data, 'success', 'Alias updated successfully.');
    } catch (error) { showResult(error?.response?.data, 'error', 'Alias update failed.'); }
    finally { aliasActionId.value = ''; }
};
const removeAlias = async (alias) => {
    if (aliasActionId.value || !confirm(`Remove alias ${alias.domain}?`)) return;
    aliasActionId.value = String(alias.id);
    try {
        const response = await window.axios.delete(panelRoute('websites.destroy', { id: alias.id }), { headers: { Accept: 'application/json' } });
        aliasRows.value = aliasRows.value.filter((item) => String(item.id) !== String(alias.id));
        showResult(response.data, 'success', 'Alias removed successfully.');
    } catch (error) { showResult(error?.response?.data, 'error', 'Alias removal failed.'); }
    finally { aliasActionId.value = ''; }
};
const issueAliasSsl = async (alias) => {
    if (aliasActionId.value) return;
    aliasActionId.value = String(alias.id);
    try {
        const response = await window.axios.post(panelRoute('websites.ssl.issue', { id: alias.id }), {}, { headers: { Accept: 'application/json' } });
        alias.enable_ssl = true; alias.ssl_status = 'valid'; alias.ssl_expires_at = response.data?.ssl?.expires_at || alias.ssl_expires_at;
        showResult(response.data, 'success', 'SSL issued successfully.');
    } catch (error) { showResult(error?.response?.data, 'error', 'SSL issue failed.'); }
    finally { aliasActionId.value = ''; }
};
const sslDaysRemaining = (alias) => {
    if (!alias.ssl_expires_at) return null;
    const expires = new Date(alias.ssl_expires_at).getTime();
    if (!Number.isFinite(expires)) return null;
    return Math.ceil((expires - Date.now()) / 86400000);
};
const sslValidityLabel = (alias) => {
    if (!alias?.enable_ssl) return 'SSL disabled';
    const days = sslDaysRemaining(alias);
    if (days === null) return 'Validity unknown';
    if (days < 0) return `Expired ${Math.abs(days)}d ago`;
    if (days === 0) return 'Expires today';
    return `Valid ${days}d`;
};
const sslValidityClass = (alias) => {
    const days = sslDaysRemaining(alias);
    if (!alias?.enable_ssl || days === null) return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
    if (days < 0) return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    if (days <= 30) return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
};
const formatExpiry = (value) => {
    if (!value) return 'Certificate expiry is unavailable';
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? String(value) : `Expires ${parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
};
const visitUrl = (alias) => `${alias.enable_ssl && ['valid', 'issued', 'renewed'].includes(String(alias.ssl_status || '').toLowerCase()) ? 'https' : 'http'}://${alias.domain}`;
const examples = [
    ['1. Verify domain', '{"action":"verify","domain":"alias.example.com"}'],
    ['2. Add alias + SSL', '{"action":"add","domain":"alias.example.com"}'],
    ['3. Remove/revoke', '{"action":"remove","domain":"alias.example.com"}'],
    ['4. Paginated list', '{"action":"list","page":1,"per_page":25}'],
];
const phpGuzzleExample = computed(() => `<?php
require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\\Client;
use GuzzleHttp\\Exception\\RequestException;

$client = new Client([
    'base_uri' => '${state.value.endpoint}',
    'timeout' => 30,
    'headers' => [
        'Authorization' => 'Bearer ' . getenv('DPANEL_ALIAS_TOKEN'),
        'Accept' => 'application/json',
    ],
]);

try {
    $verify = $client->post('', ['json' => [
        'action' => 'verify',
        'domain' => 'alias.example.com',
    ]]);
    $verification = json_decode($verify->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

    if ($verification['verified'] ?? false) {
        $response = $client->post('', ['json' => [
            'action' => 'add',
            'domain' => 'alias.example.com',
        ]]);
        print_r(json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR));
    }
} catch (RequestException $e) {
    $body = $e->getResponse()?->getBody()->getContents();
    throw new RuntimeException($body ?: $e->getMessage(), previous: $e);
}`);
const javascriptExample = computed(() => `const endpoint = '${state.value.endpoint}';
const token = process.env.DPANEL_ALIAS_TOKEN; // Keep this server-side.

async function aliasRequest(payload) {
  const response = await fetch(endpoint, {
    method: 'POST',
    headers: {
      Authorization: \`Bearer \${token}\`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.message || \`HTTP \${response.status}\`);
  return data;
}

const verification = await aliasRequest({ action: 'verify', domain: 'alias.example.com' });
if (verification.verified) {
  console.log(await aliasRequest({ action: 'add', domain: 'alias.example.com' }));
}`);
</script>
<template>

    <Head :title="`Alias API - ${website.domain}`" />
    <AuthenticatedLayout><template #header>
            <div>
                <h1 class="text-lg font-semibold">Alias API</h1>
                <p class="text-sm text-slate-500">Secure alias and SSL automation for {{ website.domain }}</p>
            </div>
        </template>
        <div class="space-y-5">
            <div class="flex flex-wrap justify-end gap-2">
                <a href="#service-api"
                    class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Alis
                    API</a>
                <Link :href="panelRoute('websites.manage', { id: website.id })"
                    class="rounded border px-3 py-2 text-sm dark:border-slate-700">Back to Website Management</Link>
            </div>
            <section id="add-alias" class="scroll-mt-5 rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">Add alias for {{ website.domain }}</h2>
                <form class="mt-4 flex flex-col gap-3 sm:flex-row" @submit.prevent="createAlias">
                    <input v-model="aliasDomain" type="text" required placeholder="alias.example.com" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="submit" :disabled="Boolean(aliasActionId)" class="rounded bg-cyan-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{{ aliasActionId === 'create' ? 'Creating...' : 'Create Alias' }}</button>
                </form>
            </section>
            <div v-if="resultMessage" :class="['rounded-xl border px-4 py-3 text-sm', resultType === 'success' ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-red-300 bg-red-50 text-red-700']">{{ resultMessage }}</div>
            <section class="overflow-hidden rounded-xl border bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b px-5 py-4 dark:border-slate-800">
                    <h2 class="font-semibold">Alias Websites</h2>
                    <p class="mt-1 text-sm text-slate-500">Aliases connected to {{ website.domain }}</p>
                </div>
                <div v-if="aliasRows.length" class="space-y-3 p-4">
                    <div v-for="alias in aliasRows" :key="alias.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-3 dark:border-slate-700 dark:bg-slate-800/40">
                        <div class="min-w-0 flex-1">
                            <div v-if="editingAliasId === String(alias.id)" class="flex max-w-lg gap-2">
                                <input v-model="editingAliasDomain" class="min-w-0 flex-1 rounded border px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" @keyup.enter="saveAliasEdit(alias)" />
                                <button type="button" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white" @click="saveAliasEdit(alias)">Save</button>
                                <button type="button" class="rounded border px-3 py-1.5 text-sm dark:border-slate-700" @click="editingAliasId = ''">Cancel</button>
                            </div>
                            <div v-else class="flex items-center gap-2">
                                <p class="truncate font-medium">{{ alias.domain }}</p>
                                <a :href="visitUrl(alias)" target="_blank" rel="noopener noreferrer" class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-cyan-200 bg-cyan-50 text-cyan-700 transition hover:border-cyan-300 hover:bg-cyan-100 dark:border-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-400" :title="`Quick visit ${alias.domain}`" :aria-label="`Quick visit ${alias.domain}`">
                                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M19 19H5V5h7V3H5c-1.11 0-1.99.9-1.99 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z" /></svg>
                                </a>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium capitalize text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>{{ alias.status || 'unknown' }}
                                </span>
                                <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="sslValidityClass(alias)" :title="formatExpiry(alias.ssl_expires_at)">
                                    <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" /></svg>
                                    <span>SSL</span><span class="opacity-40">|</span><span>{{ sslValidityLabel(alias) }}</span>
                                </span>
                            </div>
                        </div>
                        <div v-if="editingAliasId !== String(alias.id)" class="flex flex-wrap items-center gap-1.5">
                            <button type="button" :disabled="Boolean(aliasActionId)" @click="beginAliasEdit(alias)"
                                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-[12px] font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">Edit</button>
                            <button type="button" :disabled="Boolean(aliasActionId)" @click="issueAliasSsl(alias)" class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-[12px] font-medium text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">Issue SSL</button><button type="button" :disabled="Boolean(aliasActionId)"
                                class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-[12px] font-medium text-red-700 transition hover:bg-red-100 disabled:opacity-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400"
                                @click="removeAlias(alias)">Remove</button>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-10 text-center text-sm text-slate-500">No alias websites yet.</div>
            </section>
            <section v-if="result" class="rounded-xl border bg-slate-950 p-4 text-slate-100 dark:border-slate-800">
                <h2 class="mb-2 text-sm font-semibold">Last JSON response</h2>
                <pre class="overflow-x-auto text-xs leading-5">{{ JSON.stringify(result, null, 2) }}</pre>
            </section>
            <section id="service-api"
                class="flex scroll-mt-5 flex-wrap items-center justify-between gap-4 rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div>
                    <h2 class="font-semibold">Alis API</h2>
                    <p class="mt-1">Status: <strong>{{ state.enabled ? 'Enabled' : 'Disabled' }}</strong></p>
                    <p v-if="state.token_hint" class="text-xs text-slate-500">Token ending: {{ state.token_hint }}</p>
                </div>
                <div class="flex gap-2"><button :disabled="loading"
                        class="rounded bg-indigo-600 px-4 py-2 text-sm text-white" @click="rotate">{{
                            state.has_token ?'Rotate token':'Create token' }}</button><button v-if="state.has_token"
                        :disabled="loading" class="rounded border px-4 py-2 text-sm dark:border-slate-700"
                        @click="toggle">{{
                            state.enabled ?'Disable':'Enable' }}</button></div>
            </section>
            <div v-if="plainToken" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                <strong>Copy now — shown once:</strong><code class="mt-2 block break-all">{{ plainToken }}</code>
            </div>
            <div class="grid gap-5 xl:grid-cols-2">
                <section class="rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Endpoint &amp; authentication</h2><code
                        class="mt-3 block rounded bg-slate-100 p-3 text-xs dark:bg-slate-800">POST {{ state.endpoint }}</code>
                    <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">One token is scoped to this parent website. Send it only from a trusted backend; never embed it in browser JavaScript or a public repository.</p>
                    <pre class="mt-3 overflow-x-auto rounded bg-slate-950 p-4 text-xs leading-6 text-white">Authorization:
                Bearer YOUR_TOKEN
                Content-Type: application/json
                Accept: application/json</pre>
                </section>
                <section class="rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Safe flow</h2>
                    <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm">
                        <li>Create the scoped token once and store it as an encrypted server-side secret.</li>
                        <li>Point the alias DNS A/AAAA record to the same public address as {{ website.domain }}.</li>
                        <li>Call <code>verify</code>; continue only when <code>verified=true</code>.</li>
                        <li>Call <code>add</code>. Verification runs again to prevent a DNS-change race.</li>
                        <li>dPanel creates the alias and issues SSL. A failed SSL issue rolls the API-created alias back.</li>
                        <li>Use <code>list</code> to reconcile state and <code>remove</code>/<code>revoke</code> for cleanup.</li>
                    </ol>
                </section>
            </div>
            <section class="rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">Domain verification</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Verification is mandatory for both <code>verify</code> and <code>add</code>. The verifier is method-based so additional strategies can be added later without changing the request contract.</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-500/10">
                        <h3 class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">1. DNS IP match</h3>
                        <p class="mt-2 text-sm text-emerald-700 dark:text-emerald-400">Passes when the alias and parent resolve to at least one identical public A or AAAA address. Response method: <code>dns_ip_match</code>.</p>
                    </div>
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-500/10">
                        <h3 class="text-sm font-semibold text-blue-800 dark:text-blue-300">2. HTTP challenge fallback</h3>
                        <p class="mt-2 text-sm text-blue-700 dark:text-blue-400">Used when IPs do not match. The alias must resolve publicly and return the exact challenge token with HTTP 200. Redirects and private/reserved destination IPs are rejected.</p>
                    </div>
                </div>
                <div class="mt-4 grid gap-3 lg:grid-cols-2"><code
                        class="break-all rounded bg-slate-100 p-3 text-xs dark:bg-slate-800">http://ALIAS/.well-known/dpanel-alias/{{
                            state.challenge_token }}</code><code
                        class="break-all rounded bg-slate-100 p-3 text-xs dark:bg-slate-800">Response: {{ state.challenge_token
                }}</code></div>
            </section>
            <div class="grid gap-5 xl:grid-cols-3">
                <section v-for="item in examples" :key="item[0]"
                    class="rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">{{ item[0] }}</h2>
                    <pre class="mt-3 overflow-x-auto rounded bg-slate-950 p-3 text-xs text-white">{{ item[1] }}</pre>
                </section>
            </div>
            <div class="grid gap-5 xl:grid-cols-2">
                <section class="min-w-0 rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">PHP 8+ with GuzzleHTTP</h2>
                    <p class="mt-2 text-sm text-slate-500">Install with <code>composer require guzzlehttp/guzzle</code>. Keep <code>DPANEL_ALIAS_TOKEN</code> in the server environment.</p>
                    <pre class="mt-3 max-h-[34rem] overflow-auto rounded bg-slate-950 p-4 text-xs leading-5 text-white"><code>{{ phpGuzzleExample }}</code></pre>
                </section>
                <section class="min-w-0 rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">JavaScript (Node.js 18+)</h2>
                    <p class="mt-2 text-sm text-slate-500">Use this from a backend/worker. Calling from public browser code would expose the bearer token.</p>
                    <pre class="mt-3 max-h-[34rem] overflow-auto rounded bg-slate-950 p-4 text-xs leading-5 text-white"><code>{{ javascriptExample }}</code></pre>
                </section>
            </div>
            <div class="grid gap-5 xl:grid-cols-2">
                <section class="rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Pagination</h2>
                    <p class="mt-2 text-sm">Default 25, maximum 100. Continue while <code>meta.has_more</code> is true.
                        Metadata
                        contains current_page, per_page, total and last_page. Every item includes SSL status and expiry.
                    </p>
                </section>
                <section class="rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Status codes</h2>
                    <dl class="mt-3 grid grid-cols-[4rem_1fr] gap-2 text-sm">
                        <dt>200</dt>
                        <dd>Verify/list/remove success</dd>
                        <dt>201</dt>
                        <dd>Alias and SSL created</dd>
                        <dt>401</dt>
                        <dd>Invalid/disabled token</dd>
                        <dt>404</dt>
                        <dd>Scoped alias not found</dd>
                        <dt>422</dt>
                        <dd>Validation, verification, reachability or SSL failure</dd>
                        <dt>429</dt>
                        <dd>Rate limited</dd>
                    </dl>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
