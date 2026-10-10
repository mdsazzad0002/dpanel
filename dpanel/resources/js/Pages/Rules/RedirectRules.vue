<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import InputError from '@/Components/InputError.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    websites: { type: Array, default: () => [] },
    statusCodes: { type: Array, default: () => [301, 302, 307, 308] },
});

const templates = [
    {
        kind: 'http_to_https',
        title: 'Redirect from HTTP to HTTPS',
        description: 'Always redirect HTTP requests to HTTPS based on hostname.',
        icon: 'bi bi-shield-lock',
    },
    {
        kind: 'www_to_root',
        title: 'Redirect from WWW to root',
        description: 'Always redirect requests from the WWW subdomain to the root (also known as the “apex” or “naked” domain).',
        icon: 'bi bi-signpost',
    },
    {
        kind: 'to_domain',
        title: 'Redirect to a different domain',
        description: 'Redirect all requests to a different hostname using HTTPS, keeping the original path and query string.',
        icon: 'bi bi-box-arrow-up-right',
    },
];
const templateFor = (kind) => templates.find((t) => t.kind === kind) || { title: kind, icon: 'bi bi-arrow-return-right' };

const statusLabels = { 301: '301 — Permanent', 302: '302 — Temporary', 307: '307 — Temporary (keep method)', 308: '308 — Permanent (keep method)' };

const search = ref('');
const showAll = ref(false);
const ruleCount = computed(() => props.websites.reduce((sum, w) => sum + w.rules.length, 0));
const visibleWebsites = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.websites
        .filter((w) => (showAll.value || needle || w.rules.length))
        .filter((w) => !needle || w.domain.toLowerCase().includes(needle))
        .sort((a, b) => (b.rules.length > 0) - (a.rules.length > 0));
});
const websiteOptions = computed(() => props.websites.map((w) => ({ value: w.id, label: w.domain })));
const websiteById = (id) => props.websites.find((w) => w.id === id);

// What the rule does, in the same shape Cloudflare shows it.
const summary = (kind, domain, target) => {
    const host = domain || 'example.com';
    if (kind === 'http_to_https') return [`http://${host}/*`, `https://${host}/*`];
    if (kind === 'www_to_root') return [`www.${host}/*`, `${host}/*`];
    return [`${host}/*`, `https://${target || 'new-domain.com'}/*`];
};

// Offcanvas: step "template" picks a kind, step "form" fills it in.
const panelOpen = ref(false);
const step = ref('template');
const editing = ref(null);
const form = useForm({ website_id: '', kind: '', name: '', target: '', status_code: 301, preserve_query: true });
const formWebsite = computed(() => websiteById(form.website_id));
const kindTaken = computed(() => !editing.value && formWebsite.value?.rules.some((r) => r.kind === form.kind));

const openCreate = (websiteId = '') => {
    editing.value = null;
    form.clearErrors();
    // Not form.reset(): a successful submit moves the form's defaults.
    Object.assign(form, { website_id: websiteId, kind: '', name: '', target: '', status_code: 301, preserve_query: true });
    step.value = 'template';
    panelOpen.value = true;
};

const pickTemplate = (template) => {
    form.kind = template.kind;
    form.name = template.title;
    step.value = 'form';
};

const openEdit = (website, rule) => {
    editing.value = rule;
    form.clearErrors();
    Object.assign(form, {
        website_id: website.id,
        kind: rule.kind,
        name: rule.name,
        target: rule.target || '',
        status_code: rule.status_code,
        preserve_query: rule.preserve_query,
    });
    step.value = 'form';
    panelOpen.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => { panelOpen.value = false; } };
    if (editing.value) {
        form.put(panelRoute('rules.redirects.update', { rule: editing.value.id }), options);
    } else {
        form.post(panelRoute('rules.redirects.store'), options);
    }
};

const toggle = (rule) => {
    router.patch(panelRoute('rules.redirects.toggle', { rule: rule.id }), {}, { preserveScroll: true });
};

const remove = (website, rule) => {
    if (!confirm(`Delete "${rule.name}" from ${website.domain}?`)) return;
    router.delete(panelRoute('rules.redirects.destroy', { rule: rule.id }), { preserveScroll: true });
};

const firstError = computed(() => page.props.errors?.website_id || page.props.errors?.kind);
</script>

<template>
    <Head title="Redirect Rules" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Redirect Rules</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Redirects answered by the edge gateway before your site is reached.</p>
                </div>
                <button @click="openCreate()" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    <i class="bi bi-plus-lg mr-1"></i>Create redirect rule
                </button>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>
            <div v-if="firstError && !panelOpen" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ firstError }}</div>

            <div v-if="websites.length" class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <input v-model="search" type="search" placeholder="Search website…" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input v-model="showAll" type="checkbox" class="rounded border-slate-300" />
                    Show websites without rules
                </label>
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ ruleCount }} {{ ruleCount === 1 ? 'rule' : 'rules' }}</span>
            </div>

            <div v-if="!visibleWebsites.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-600 dark:bg-slate-800">
                <i class="bi bi-signpost-2 text-3xl text-slate-400"></i>
                <p class="mt-2 text-sm font-medium">{{ websites.length ? 'No redirect rules yet' : 'No websites yet' }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ websites.length ? 'Start from a template: HTTPS, www to root, or a different domain.' : 'Create a website first, then add redirect rules to it.' }}
                </p>
                <button v-if="websites.length" @click="openCreate()" class="mt-4 rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">Create redirect rule</button>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div
                    v-for="website in visibleWebsites"
                    :key="website.id"
                    class="flex flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800"
                >
                    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ website.domain }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <i class="bi" :class="website.enable_ssl ? 'bi-lock-fill text-emerald-500' : 'bi-unlock text-amber-500'"></i>
                                {{ website.enable_ssl ? 'SSL active' : 'No SSL' }} · {{ website.rules.length }} {{ website.rules.length === 1 ? 'rule' : 'rules' }}
                            </p>
                        </div>
                        <button @click="openCreate(website.id)" class="shrink-0 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700">
                            <i class="bi bi-plus-lg"></i> Add rule
                        </button>
                    </div>

                    <ul v-if="website.rules.length" class="divide-y divide-slate-100 dark:divide-slate-700">
                        <li v-for="rule in website.rules" :key="rule.id" class="flex items-start gap-3 px-4 py-3" :class="{ 'opacity-60': !rule.enabled }">
                            <i class="mt-0.5 text-slate-400" :class="templateFor(rule.kind).icon"></i>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ rule.name }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-1 font-mono text-xs text-slate-500 dark:text-slate-400">
                                    <span class="truncate">{{ summary(rule.kind, website.domain, rule.target)[0] }}</span>
                                    <i class="bi bi-arrow-right"></i>
                                    <span class="truncate">{{ summary(rule.kind, website.domain, rule.target)[1] }}</span>
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 font-sans dark:bg-slate-700">{{ rule.status_code }}</span>
                                </p>
                            </div>
                            <button
                                @click="toggle(rule)"
                                role="switch"
                                :aria-checked="rule.enabled"
                                :title="rule.enabled ? 'Disable' : 'Enable'"
                                class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition"
                                :class="rule.enabled ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"
                            >
                                <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition" :class="rule.enabled ? 'left-[18px]' : 'left-0.5'"></span>
                            </button>
                            <button @click="openEdit(website, rule)" title="Edit" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200"><i class="bi bi-pencil"></i></button>
                            <button @click="remove(website, rule)" title="Delete" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"><i class="bi bi-trash"></i></button>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No redirect rules on this website.</p>
                </div>
            </div>
        </div>

        <Offcanvas
            :show="panelOpen"
            width="lg"
            :title="editing ? 'Edit redirect rule' : (step === 'template' ? 'Create new redirect rule' : templateFor(form.kind).title)"
            :subtitle="step === 'template' ? 'Start from a template.' : templateFor(form.kind).description"
            @close="panelOpen = false"
        >
            <div v-if="step === 'template'" class="space-y-3">
                <div
                    v-for="template in templates"
                    :key="template.kind"
                    class="rounded-xl border border-slate-200 p-4 dark:border-slate-700"
                >
                    <div class="flex items-start gap-3">
                        <i class="mt-0.5 text-lg text-blue-600 dark:text-blue-400" :class="template.icon"></i>
                        <div class="flex-1">
                            <p class="font-semibold">{{ template.title }}</p>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ template.description }}</p>
                            <button @click="pickTemplate(template)" class="mt-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-800">
                                Create from template
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <form v-else class="space-y-4" @submit.prevent="submit">
                <button v-if="!editing" type="button" @click="step = 'template'" class="text-sm text-blue-600 hover:underline dark:text-blue-400">
                    <i class="bi bi-arrow-left"></i> Templates
                </button>

                <div>
                    <label class="mb-1 block text-sm font-medium">Website</label>
                    <SearchableSelect
                        v-model="form.website_id"
                        :options="websiteOptions"
                        :disabled="!!editing"
                        placeholder="Select a website…"
                        search-placeholder="Search domains…"
                    />
                    <InputError :message="form.errors.website_id" class="mt-1" />
                    <p v-if="form.kind === 'http_to_https' && formWebsite && !formWebsite.enable_ssl" class="mt-1 text-xs text-amber-600">
                        This website has no SSL certificate yet. Issue one before turning on HTTPS redirects.
                    </p>
                    <p v-if="kindTaken" class="mt-1 text-xs text-amber-600">This website already has this rule. Edit the existing one instead.</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Rule name</label>
                    <input v-model="form.name" type="text" maxlength="120" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>

                <div v-if="form.kind === 'to_domain'">
                    <label class="mb-1 block text-sm font-medium">Target hostname</label>
                    <div class="flex items-center rounded-lg border border-slate-300 dark:border-slate-600">
                        <span class="px-3 text-sm text-slate-500">https://</span>
                        <input v-model="form.target" type="text" placeholder="new-domain.com" class="w-full rounded-r-lg border-0 text-sm focus:ring-0 dark:bg-slate-900" />
                    </div>
                    <InputError :message="form.errors.target" class="mt-1" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Status code</label>
                        <select v-model.number="form.status_code" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <option v-for="code in statusCodes" :key="code" :value="code">{{ statusLabels[code] || code }}</option>
                        </select>
                        <InputError :message="form.errors.status_code" class="mt-1" />
                    </div>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm">
                        <input v-model="form.preserve_query" type="checkbox" class="rounded border-slate-300" />
                        Preserve query string
                    </label>
                </div>

                <div class="rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800">
                    <p class="mb-1 font-medium text-slate-600 dark:text-slate-300">Preview</p>
                    <p class="flex flex-wrap items-center gap-1 font-mono text-slate-500 dark:text-slate-400">
                        <span>{{ summary(form.kind, formWebsite?.domain, form.target)[0] }}</span>
                        <i class="bi bi-arrow-right"></i>
                        <span>{{ summary(form.kind, formWebsite?.domain, form.target)[1] }}</span>
                        <span class="rounded bg-white px-1.5 py-0.5 font-sans dark:bg-slate-700">{{ form.status_code }}</span>
                    </p>
                    <p class="mt-2 text-slate-500 dark:text-slate-400">The path is always kept. Certificate checks under /.well-known/acme-challenge/ are never redirected.</p>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
                    <button type="button" @click="panelOpen = false" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600">Cancel</button>
                    <button type="submit" :disabled="form.processing || !form.website_id || kindTaken" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        {{ editing ? 'Save' : 'Deploy' }}
                    </button>
                </div>
            </form>
        </Offcanvas>
    </AuthenticatedLayout>
</template>
