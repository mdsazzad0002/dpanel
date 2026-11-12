<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

// In-page developer guide for System → Extensions. The long form is
// docs/extensions.md; keep the two in step. Code samples are not translated.
const props = defineProps({
    apiBase: { type: String, default: '' },
    events: { type: Array, default: () => [] },
});
const { t } = useI18n();

const STORAGE_KEY = 'dpanel.extensions.docs';
const read = (fallback) => {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) ?? fallback; } catch { return fallback; }
};
const state = read({ open: false, tab: 'overview' });
const open = ref(Boolean(state.open));
const tab = ref(state.tab || 'overview');
watch([open, tab], () => {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify({ open: open.value, tab: tab.value })); } catch { /* per-viewer convenience only */ }
});

const tabs = ['overview', 'manifest', 'webhooks', 'page', 'limits'];

const copied = ref('');
const copy = async (key, value) => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => { if (copied.value === key) copied.value = ''; }, 1500);
    } catch {
        copied.value = '';
    }
};

const panelHost = computed(() => {
    try { return new URL(props.apiBase).host; } catch { return 'panel.example.com'; }
});
const apiBase = computed(() => props.apiBase || 'https://panel.example.com/api/v1');

const samples = computed(() => ({
    manifest: JSON.stringify({
        manifest_version: 1,
        id: 'acme.uptime',
        name: 'Uptime report',
        version: '1.0.0',
        description: 'Checks every website once a minute.',
        author: 'Acme',
        homepage: 'https://acme.example/uptime',
        panel: '>=1.0 <2.0',
        scopes: ['websites:read'],
        ui: { admin_page: { title: 'Uptime', url: 'https://uptime.example.com/dpanel' } },
        webhook: { url: 'https://uptime.example.com/dpanel/events' },
        events: props.events.length ? props.events : ['website.created', 'website.deleted'],
    }, null, 4),
    api: `curl -H "Authorization: Bearer dp-api-..." \\\n     ${apiBase.value}/websites`,
    payload: JSON.stringify({
        extension: 'acme.uptime',
        id: '5f0c...',
        event: 'website.created',
        occurred_at: '2026-11-12T10:00:00+00:00',
        data: { id: '...', domain: 'shop.example.com', type: 'primary', parent_id: null, owner_id: 7 },
    }, null, 4),
    verifyPhp: `$timestamp = (int) $_SERVER['HTTP_X_DPANEL_TIMESTAMP'];
$body = file_get_contents('php://input');
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
if (! hash_equals($expected, $_SERVER['HTTP_X_DPANEL_SIGNATURE'] ?? '')
    || abs(time() - $timestamp) > 300) {
    http_response_code(401);
    exit;
}
$event = json_decode($body, true); // reply 2xx within 10 s`,
    verifyNode: `import crypto from 'node:crypto';

// app.use(express.raw({ type: 'application/json' }))
app.post('/dpanel/events', (req, res) => {
    const ts = Number(req.get('X-Dpanel-Timestamp'));
    const body = req.body.toString('utf8');
    const expected = 'sha256=' + crypto.createHmac('sha256', SECRET).update(\`\${ts}.\${body}\`).digest('hex');
    const given = req.get('X-Dpanel-Signature') || '';
    const ok = given.length === expected.length
        && crypto.timingSafeEqual(Buffer.from(given), Buffer.from(expected))
        && Math.abs(Date.now() / 1000 - ts) <= 300;
    if (!ok) return res.sendStatus(401);
    const event = JSON.parse(body);
    res.sendStatus(204);
});`,
    context: `// ?dpanel_context=<base64url JSON>.<base64url HMAC-SHA256>
[$payload, $signature] = explode('.', $_GET['dpanel_context'] ?? '', 2) + [1 => ''];
$b64 = fn ($v) => base64_decode(strtr($v, '-_', '+/'));
$valid = hash_equals(hash_hmac('sha256', $payload, $secret, true), $b64($signature));
$context = json_decode($b64($payload), true);
if (! $valid || ($context['exp'] ?? 0) < time()) {
    http_response_code(403);
    exit;
}
// $context['user'] = ['id' => ..., 'name' => ..., 'role' => 'admin']`,
    frameHeader: `Content-Security-Policy: frame-ancestors https://${panelHost.value}`,
}));

const manifestKeys = [
    ['manifest_version', true],
    ['id', true],
    ['name', true],
    ['version', true],
    ['description', false],
    ['homepage', false],
    ['panel', true],
    ['scopes', false],
    ['ui.admin_page', false],
    ['webhook.url', false],
    ['events', false],
];
const headers = ['X-Dpanel-Event', 'X-Dpanel-Delivery', 'X-Dpanel-Timestamp', 'X-Dpanel-Signature'];
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <button type="button" class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left" :aria-expanded="open" @click="open = !open">
            <span class="flex items-center gap-3">
                <i class="bi bi-book text-lg text-indigo-500"></i>
                <span>
                    <span class="block font-semibold">{{ t('extensions.docs.title') }}</span>
                    <span class="block text-xs text-slate-500">{{ t('extensions.docs.subtitle') }}</span>
                </span>
            </span>
            <i class="bi text-slate-400" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <div v-if="open" class="border-t border-slate-200 dark:border-slate-800">
            <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 px-3 dark:border-slate-800" role="tablist">
                <button
                    v-for="key in tabs"
                    :key="key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === key"
                    class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium"
                    :class="tab === key ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'"
                    @click="tab = key"
                >
                    {{ t(`extensions.docs.tabs.${key}`) }}
                </button>
            </nav>

            <div class="docs space-y-4 p-5 text-sm leading-6 text-slate-700 dark:text-slate-300">
                <!-- Overview -->
                <template v-if="tab === 'overview'">
                    <p>{{ t('extensions.docs.overview.intro') }}</p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li v-for="n in 5" :key="n">{{ t(`extensions.docs.overview.how${n}`) }}</li>
                    </ul>
                    <h3>{{ t('extensions.docs.overview.installTitle') }}</h3>
                    <ol class="list-decimal space-y-1 pl-5">
                        <li v-for="n in 4" :key="n">{{ t(`extensions.docs.overview.install${n}`) }}</li>
                    </ol>
                    <h3>{{ t('extensions.docs.overview.apiTitle') }}</h3>
                    <p>{{ t('extensions.docs.overview.apiText') }}</p>
                    <div class="code">
                        <button type="button" @click="copy('api', samples.api)">{{ copied === 'api' ? t('extensions.copied') : t('extensions.copy') }}</button>
                        <pre>{{ samples.api }}</pre>
                    </div>
                    <h3>{{ t('extensions.docs.overview.manageTitle') }}</h3>
                    <ul class="list-disc space-y-1 pl-5">
                        <li v-for="key in ['disable', 'ping', 'rotate', 'update', 'uninstall']" :key="key">
                            <strong>{{ t(`extensions.docs.overview.manage.${key}.label`) }}</strong> — {{ t(`extensions.docs.overview.manage.${key}.text`) }}
                        </li>
                    </ul>
                </template>

                <!-- Manifest -->
                <template v-else-if="tab === 'manifest'">
                    <p>{{ t('extensions.docs.manifest.intro') }}</p>
                    <div class="code">
                        <button type="button" @click="copy('manifest', samples.manifest)">{{ copied === 'manifest' ? t('extensions.copied') : t('extensions.copy') }}</button>
                        <pre>{{ samples.manifest }}</pre>
                    </div>
                    <div class="overflow-x-auto">
                        <table>
                            <thead>
                                <tr><th>{{ t('extensions.docs.key') }}</th><th>{{ t('extensions.docs.required') }}</th><th>{{ t('extensions.docs.meaning') }}</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="[key, required] in manifestKeys" :key="key">
                                    <td><code>{{ key }}</code></td>
                                    <td>{{ required ? t('extensions.docs.yes') : t('extensions.docs.no') }}</td>
                                    <td>{{ t(`extensions.docs.manifest.keys.${key.replace('.', '_')}`) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="note">{{ t('extensions.docs.manifest.urls') }}</p>
                    <p class="note">{{ t('extensions.docs.manifest.strict') }}</p>
                </template>

                <!-- Webhooks -->
                <template v-else-if="tab === 'webhooks'">
                    <p>{{ t('extensions.docs.webhooks.intro') }}</p>
                    <div class="overflow-x-auto">
                        <table>
                            <thead><tr><th>{{ t('extensions.docs.event') }}</th><th>{{ t('extensions.docs.scope') }}</th></tr></thead>
                            <tbody>
                                <tr v-for="event in events" :key="event"><td><code>{{ event }}</code></td><td><code>websites:read</code></td></tr>
                                <tr><td><code>ping</code></td><td>{{ t('extensions.docs.webhooks.pingOnly') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <h3>{{ t('extensions.docs.webhooks.bodyTitle') }}</h3>
                    <div class="code">
                        <button type="button" @click="copy('payload', samples.payload)">{{ copied === 'payload' ? t('extensions.copied') : t('extensions.copy') }}</button>
                        <pre>{{ samples.payload }}</pre>
                    </div>
                    <div class="overflow-x-auto">
                        <table>
                            <thead><tr><th>{{ t('extensions.docs.header') }}</th><th>{{ t('extensions.docs.meaning') }}</th></tr></thead>
                            <tbody>
                                <tr v-for="header in headers" :key="header"><td><code>{{ header }}</code></td><td>{{ t(`extensions.docs.webhooks.headers.${header.replace(/-/g, '_')}`) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <h3>{{ t('extensions.docs.webhooks.verifyTitle') }}</h3>
                    <p>{{ t('extensions.docs.webhooks.verifyText') }}</p>
                    <p class="label">PHP</p>
                    <div class="code">
                        <button type="button" @click="copy('php', samples.verifyPhp)">{{ copied === 'php' ? t('extensions.copied') : t('extensions.copy') }}</button>
                        <pre>{{ samples.verifyPhp }}</pre>
                    </div>
                    <p class="label">Node.js (Express)</p>
                    <div class="code">
                        <button type="button" @click="copy('node', samples.verifyNode)">{{ copied === 'node' ? t('extensions.copied') : t('extensions.copy') }}</button>
                        <pre>{{ samples.verifyNode }}</pre>
                    </div>
                    <p class="note">{{ t('extensions.docs.webhooks.retry') }}</p>
                </template>

                <!-- Admin page -->
                <template v-else-if="tab === 'page'">
                    <p>{{ t('extensions.docs.page.intro') }}</p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li v-for="n in 3" :key="n">{{ t(`extensions.docs.page.point${n}`) }}</li>
                    </ul>
                    <h3>{{ t('extensions.docs.page.frameTitle') }}</h3>
                    <p>{{ t('extensions.docs.page.frameText') }}</p>
                    <div class="code">
                        <button type="button" @click="copy('frame', samples.frameHeader)">{{ copied === 'frame' ? t('extensions.copied') : t('extensions.copy') }}</button>
                        <pre>{{ samples.frameHeader }}</pre>
                    </div>
                    <h3>{{ t('extensions.docs.page.contextTitle') }}</h3>
                    <p>{{ t('extensions.docs.page.contextText') }}</p>
                    <div class="code">
                        <button type="button" @click="copy('context', samples.context)">{{ copied === 'context' ? t('extensions.copied') : t('extensions.copy') }}</button>
                        <pre>{{ samples.context }}</pre>
                    </div>
                </template>

                <!-- Limits -->
                <template v-else>
                    <ul class="list-disc space-y-1 pl-5">
                        <li v-for="n in 4" :key="n">{{ t(`extensions.docs.limits.item${n}`) }}</li>
                    </ul>
                    <p class="note">{{ t('extensions.docs.limits.more') }}</p>
                </template>
            </div>
        </div>
    </section>
</template>

<style scoped>
.docs h3 { padding-top: .5rem; font-size: .875rem; font-weight: 600; color: #0f172a; }
.docs table { width: 100%; border-collapse: collapse; text-align: left; font-size: .75rem; }
.docs th { border-bottom: 1px solid #e2e8f0; padding: .5rem .75rem; font-weight: 600; color: #64748b; }
.docs td { border-bottom: 1px solid #f1f5f9; padding: .5rem .75rem; vertical-align: top; }
.docs td code { border-radius: .25rem; background: #f1f5f9; padding: .125rem .25rem; font-size: 11px; color: #1e293b; }
.docs .code { position: relative; border-radius: .75rem; background: #020617; color: #f1f5f9; }
.docs .code pre { overflow-x: auto; padding: 1rem 5rem 1rem 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; line-height: 1.25rem; white-space: pre; }
.docs .code button { position: absolute; right: .5rem; top: .5rem; border-radius: .375rem; border: 1px solid #334155; padding: .25rem .5rem; font-size: .75rem; font-weight: 600; color: #cbd5e1; }
.docs .code button:hover { background: #1e293b; }
.docs .note { border-radius: .5rem; border: 1px solid #fde68a; background: #fffbeb; padding: .5rem .75rem; font-size: .75rem; color: #92400e; }
.docs .label { margin-bottom: -.5rem; font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
:global(.dark) .docs h3 { color: #f1f5f9; }
:global(.dark) .docs th { border-color: #334155; }
:global(.dark) .docs td { border-color: #1e293b; }
:global(.dark) .docs td code { background: #1e293b; color: #e2e8f0; }
:global(.dark) .docs .note { border-color: #78350f; background: rgba(69, 26, 3, .4); color: #fde68a; }
</style>
