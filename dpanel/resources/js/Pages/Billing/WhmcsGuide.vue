<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    status: { type: Object, required: true },
    clientFile: { type: String, required: true },
});

const page = usePage();
const copied = ref('');
const panelRoute = (name, params = {}) => route(name, { token: page.props.panel?.token, ...params });
const copy = async (value, key) => {
    await navigator.clipboard.writeText(value);
    copied.value = key;
    window.setTimeout(() => { copied.value = ''; }, 1500);
};

const envExample = `WHMCS_API_CLIENT_ID=whmcs-production
WHMCS_API_SECRET=generate-a-long-random-secret
WHMCS_ALLOWED_IPS=203.0.113.10
WHMCS_ALLOWED_DOMAINS=billing.example.com
WHMCS_TIMESTAMP_TOLERANCE=300
WHMCS_SSO_TTL=60`;

const projectDirectory = props.clientFile.replace('/integrations/whmcs/DPanelApiClient.php', '');
const baseSetupCommand = () => `cd ${projectDirectory} && sudo bash integrations/whmcs/configure.sh \\
  --client-id whmcs-production \\
  --allowed-ip 203.0.113.10 \\
  --allowed-domain billing.example.com`;
const setupCommand = ref(baseSetupCommand());

const generateSecret = () => {
    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    const secret = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    const commandWithoutSecret = setupCommand.value
        .replace(/\s*\\?\s*--secret\s+\S+/g, '')
        .replace(/\s*\\?\s*--rotate-secret\b/g, '')
        .trimEnd();
    setupCommand.value = `${commandWithoutSecret} \\
  --secret ${secret}`;
};

const operations = [
    { label: 'Connection Test', icon: 'bi-plug' },
    { label: 'Plan Sync', icon: 'bi-arrow-repeat' },
    { label: 'Create Account', icon: 'bi-person-plus' },
    { label: 'Change Plan', icon: 'bi-arrow-left-right' },
    { label: 'Suspend', icon: 'bi-pause-circle' },
    { label: 'Unsuspend', icon: 'bi-play-circle' },
    { label: 'Terminate', icon: 'bi-x-circle' },
    { label: 'Auto Login', icon: 'bi-box-arrow-in-right' },
];
</script>

<template>
    <Head title="Billing with WHMCS" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Billing with WHMCS</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Backend-only provisioning, lifecycle automation and one-time SSO.</p>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link :href="panelRoute('billing.index')" class="text-sm text-blue-600 hover:underline">← Billing services</Link>
                <span :class="status.ready ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'" class="rounded-full px-3 py-1 text-sm font-medium">
                    {{ status.ready ? 'Integration ready' : 'Configuration incomplete' }}
                </span>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">1. Configure dPanel</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Add these values to dPanel’s <code>.env</code>. Use the public outbound IP of the WHMCS server; multiple IPs or CIDRs are comma-separated.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">Client ID<br><strong>{{ status.clientIdReady ? 'Set' : 'Missing' }}</strong></div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">API secret<br><strong>{{ status.secretReady ? 'Set' : 'Missing' }}</strong></div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">Allowed IPs<br><strong>{{ status.allowedIps.length ? status.allowedIps.join(', ') : 'Missing' }}</strong></div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">Allowed domains<br><strong>{{ status.allowedDomains.length ? status.allowedDomains.join(', ') : 'Missing' }}</strong></div>
                </div>
                <div class="relative mt-4">
                    <pre class="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100"><code>{{ envExample }}</code></pre>
                    <button class="absolute right-3 top-3 rounded bg-slate-700 px-2 py-1 text-xs text-white" @click="copy(envExample, 'env')">{{ copied === 'env' ? 'Copied' : 'Copy' }}</button>
                </div>
                <p class="mt-3 text-sm">Then run: <code class="rounded bg-slate-100 px-2 py-1 dark:bg-slate-800">php artisan config:cache</code></p>
                <div class="mt-5 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                    <h3 class="text-sm font-semibold">One-command setup and .env sync</h3>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">Replace the example IP and domain, then copy/paste. Existing keys are updated, missing keys are added, and an existing secret is preserved.</p>
                    <div class="mt-3">
                        <textarea v-model="setupCommand" rows="7" spellcheck="false" class="w-full rounded-lg border-0 bg-slate-950 p-4 font-mono text-xs leading-6 text-slate-100 focus:ring-2 focus:ring-blue-500"></textarea>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" class="rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700" @click="generateSecret">
                                <i class="bi bi-key mr-1"></i> Generate Secret
                            </button>
                            <button type="button" class="rounded bg-slate-700 px-3 py-2 text-xs font-medium text-white hover:bg-slate-600" @click="copy(setupCommand, 'setup')">
                                <i class="bi bi-clipboard mr-1"></i> {{ copied === 'setup' ? 'Copied' : 'Copy Command' }}
                            </button>
                            <button type="button" class="rounded border border-slate-300 px-3 py-2 text-xs font-medium hover:bg-white dark:border-slate-700 dark:hover:bg-slate-800" @click="setupCommand = baseSetupCommand()">Reset</button>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-slate-600 dark:text-slate-400">Edit the IP/domain first, generate a secret, then copy the command. Generating again replaces only the secret in this textarea.</p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Available operations</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span v-for="operation in operations" :key="operation.label" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <i :class="['bi', operation.icon]" class="text-blue-600 dark:text-blue-400"></i>
                        {{ operation.label }}
                    </span>
                </div>
            </section>

            <section class="grid gap-6 md:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">Security</h2>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-600 dark:text-slate-400">
                        <li>dPanel rejects requests before processing unless the source IP and signed WHMCS domain both match their allowlists.</li>
                        <li>Every request is HMAC-SHA256 signed with method, path, WHMCS domain, timestamp, nonce and raw-body hash.</li>
                        <li>Timestamps older than {{ status.timestampTolerance }} seconds and reused nonces are rejected.</li>
                        <li>SSO URLs expire after {{ status.ssoTtl }} seconds and are consumed only once. Redirect the browser only to the returned URL.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">Troubleshooting</h2>
                    <dl class="mt-3 space-y-3 text-sm">
                        <div><dt class="font-medium">403 Forbidden</dt><dd class="text-slate-600 dark:text-slate-400">Check WHMCS outbound IP and proxy forwarding.</dd></div>
                        <div><dt class="font-medium">401 Unauthorized</dt><dd class="text-slate-600 dark:text-slate-400">Client ID, shared secret, signature or server clock does not match.</dd></div>
                        <div><dt class="font-medium">409 Conflict</dt><dd class="text-slate-600 dark:text-slate-400">Nonce was replayed or the email belongs to another account.</dd></div>
                        <div><dt class="font-medium">422 Unprocessable</dt><dd class="text-slate-600 dark:text-slate-400">The plan slug or required service/customer data is invalid.</dd></div>
                    </dl>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
