<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ website: Object, sshHost: String, sshPort: Number });
const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);
const csrf = computed(() => document.querySelector('meta[name="csrf-token"]')?.content || '');
const comment = ref(`github-actions@${props.website.domain}`);
const busy = ref(false);
const error = ref('');
const result = ref(null);
const copied = ref('');

const generate = async () => {
    if (result.value && !confirm('বর্তমান private key আর দেখা যাবে না। নতুন key তৈরি করবেন?')) return;
    busy.value = true; error.value = ''; result.value = null;
    try {
        const response = await fetch(panelRoute('websites.ssh-key.generate', { id: props.website.id }), {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf.value, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ comment: comment.value }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(' ') || 'Key generation failed.');
        result.value = data.key;
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
};
const copy = async (name, value) => {
    try {
        await navigator.clipboard.writeText(String(value || ''));
        copied.value = name;
        window.setTimeout(() => { copied.value = ''; }, 1800);
    } catch (e) { error.value = 'Clipboard-এ copy করা যায়নি।'; }
};
const downloadPrivateKey = () => {
    if (!result.value?.private_key) return;
    const url = URL.createObjectURL(new Blob([result.value.private_key], { type: 'application/octet-stream' }));
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = `${props.website.domain}-deploy-key`; anchor.click();
    URL.revokeObjectURL(url);
};
</script>

<template>
    <Head :title="`SSH Key Generator - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div><h1 class="text-lg font-semibold">SSH Key Generator</h1><p class="text-sm text-slate-500">Create secure GitHub deployment access for {{ website.domain }}</p></div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-lg border px-3 py-2 text-sm dark:border-slate-700"><i class="bi bi-arrow-left mr-1"></i> Website dashboard</Link>
            </div>
        </template>

        <div class="mx-auto  space-y-6 p-4 sm:p-6">
            <div v-if="error" class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800"><i class="bi bi-exclamation-triangle-fill mr-2"></i>{{ error }}</div>

            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 text-amber-700"><i class="bi bi-key-fill"></i></span><div><h2 class="font-semibold">Generate ED25519 key</h2><p class="mt-1 text-sm text-slate-500">Public key automatically <code>/home/{{ website.site_owner }}/.ssh/authorized_keys</code>-এ install হবে।</p></div></div>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium">Website user<input :value="website.site_owner" disabled class="mt-1.5 w-full rounded-lg border-slate-300 bg-slate-100 dark:bg-slate-800" /></label>
                    <label class="text-sm font-medium">Key comment<input v-model.trim="comment" maxlength="120" class="mt-1.5 w-full rounded-lg border-slate-300 dark:bg-slate-800" /></label>
                </div>
                <button type="button" @click="generate" :disabled="busy || !comment" class="mt-5 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><span v-if="busy" class="mr-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-white border-r-transparent"></span><i v-else class="bi bi-shield-lock mr-2"></i>{{ busy ? 'Generating…' : result ? 'Generate new key' : 'Generate & install key' }}</button>
            </section>

            <section v-if="result" class="rounded-xl border border-emerald-300 bg-white p-5 shadow-sm dark:border-emerald-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="font-semibold text-emerald-700"><i class="bi bi-check-circle-fill mr-2"></i>Key generated and installed</h2><p class="mt-1 text-xs text-slate-500">{{ result.fingerprint }}</p></div><button @click="downloadPrivateKey" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white"><i class="bi bi-download mr-2"></i>Download private key</button></div>
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"><strong>Private key শুধু এখনই দেখানো হবে।</strong> Download করে নিরাপদে রাখুন এবং GitHub secret-এ যোগ করুন।</div>

                <div class="mt-5 space-y-4">
                    <div><div class="mb-1.5 flex justify-between"><label class="text-sm font-medium">SSH_PRIVATE_KEY</label><button @click="copy('private', result.private_key)" class="text-xs text-indigo-600"><i class="bi bi-copy mr-1"></i>{{ copied === 'private' ? 'Copied' : 'Copy' }}</button></div><pre class="max-h-56 overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-200"><code>{{ result.private_key }}</code></pre></div>
                    <div><div class="mb-1.5 flex justify-between"><label class="text-sm font-medium">Public key (already installed)</label><button @click="copy('public', result.public_key)" class="text-xs text-indigo-600"><i class="bi bi-copy mr-1"></i>{{ copied === 'public' ? 'Copied' : 'Copy' }}</button></div><pre class="overflow-auto rounded-lg bg-slate-100 p-4 text-xs dark:bg-slate-800"><code>{{ result.public_key }}</code></pre></div>
                </div>
            </section>

            <section class="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <h2 class="font-semibold">GitHub Actions secrets</h2><p class="mt-1 text-sm text-slate-500">Repository → Settings → Secrets and variables → Actions-এ এগুলো দিন।</p>
                <div class="mt-4 divide-y rounded-lg border text-sm dark:divide-slate-700 dark:border-slate-700">
                    <div v-for="item in [['SSH_HOST', sshHost], ['SSH_USER', website.site_owner], ['SSH_PORT', sshPort]]" :key="item[0]" class="flex items-center justify-between gap-3 p-3"><div><code class="font-semibold">{{ item[0] }}</code><p class="mt-1 text-xs text-slate-500">{{ item[1] }}</p></div><button @click="copy(item[0], item[1])" class="rounded border px-3 py-1.5 text-xs dark:border-slate-700">{{ copied === item[0] ? 'Copied' : 'Copy' }}</button></div>
                    <div class="p-3"><code class="font-semibold">SSH_PRIVATE_KEY</code><p class="mt-1 text-xs text-slate-500">উপরে generate করা সম্পূর্ণ private key দিন।</p></div>
                    <div class="p-3"><code class="font-semibold">SSH_KNOWN_HOSTS</code><p class="mt-1 text-xs text-slate-500">Trusted computer থেকে চালান: <code>ssh-keyscan -p {{ sshPort }} {{ sshHost }}</code></p></div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
