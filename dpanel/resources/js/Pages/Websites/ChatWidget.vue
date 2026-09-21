<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ website: { type: Object, required: true }, chatWidget: { type: Object, required: true } });
const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);

const state = ref({ ...props.chatWidget });
const loading = ref(false);
const copied = ref(false);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

const connect = async () => {
    loading.value = true;
    try {
        const r = await fetch(panelRoute('websites.chat-widget.connect', { id: props.website.id }), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (r.ok) state.value = await r.json();
    } finally {
        loading.value = false;
    }
};

const toggle = async () => {
    loading.value = true;
    try {
        const r = await fetch(panelRoute('websites.chat-widget.toggle', { id: props.website.id }), {
            method: 'PATCH',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ enabled: !state.value.is_active }),
        });
        if (r.ok) state.value = await r.json();
    } finally {
        loading.value = false;
    }
};

const copyScript = async () => {
    try {
        await navigator.clipboard.writeText(state.value.embed_script || '');
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch (e) {}
};
</script>
<template>

    <Head :title="`AI Chat Widget - ${website.domain}`" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">AI Chat Widget</h1>
                <p class="text-sm text-slate-500">Embeddable AI chat for {{ website.domain }}</p>
            </div>
        </template>
        <div class="space-y-5">
            <div class="flex flex-wrap justify-end gap-2">
                <Link :href="panelRoute('websites.manage', { id: website.id })"
                    class="rounded border px-3 py-2 text-sm dark:border-slate-700">Back to Website Management</Link>
            </div>

            <section v-if="!state.connected" class="rounded-xl border bg-white p-6 text-center dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">Connect AI chat to {{ website.domain }}</h2>
                <p class="mt-2 text-sm text-slate-500">Generate a ready-to-paste embed script that adds an AI chat widget to your website.</p>
                <button type="button" :disabled="loading" class="mt-4 rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60" @click="connect">
                    {{ loading ? 'Connecting...' : 'Connect &amp; Generate Script' }}
                </button>
            </section>

            <template v-else>
                <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div>
                        <h2 class="font-semibold">Widget status</h2>
                        <p class="mt-1 text-sm">Status: <strong>{{ state.is_active ? 'Enabled' : 'Disabled' }}</strong></p>
                    </div>
                    <button type="button" :disabled="loading" class="rounded border px-4 py-2 text-sm dark:border-slate-700" @click="toggle">
                        {{ state.is_active ? 'Disable' : 'Enable' }}
                    </button>
                </section>

                <section class="rounded-xl border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Embed script</h2>
                        <button type="button" class="rounded bg-slate-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-700" @click="copyScript">
                            {{ copied ? 'Copied!' : 'Copy' }}
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Paste this snippet right before the closing <code>&lt;/body&gt;</code> tag on every page you want the chat bubble to appear.</p>
                    <pre class="mt-3 overflow-x-auto rounded bg-slate-950 p-4 text-xs leading-6 text-white"><code>{{ state.embed_script }}</code></pre>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
