<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChatEngineDocsButton from '@/Components/ChatEngineDocsButton.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    business: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    assignedChannels: { type: Array, default: () => [] },
    unassignedChannels: { type: Array, default: () => [] },
});

// --- Business details ---
const detailsForm = useForm({
    name: props.business.name,
    industry: props.business.industry || '',
    description: props.business.description || '',
    reply_language: props.business.reply_language || '',
    integration_base_url: props.business.integration_base_url || '',
    integration_api_key: '',
    search_enabled: props.business.search_enabled,
    order_enabled: props.business.order_enabled,
    email_enabled: props.business.email_enabled,
    sms_enabled: props.business.sms_enabled,
});
const detailsSuccess = ref('');
const detailsError = ref('');
const saveDetails = async () => {
    detailsSuccess.value = '';
    detailsError.value = '';
    detailsForm.clearErrors();
    detailsForm.processing = true;
    try {
        await axios.patch(panelRoute('chat-engine.businesses.update', { business: props.business.id }), {
            name: detailsForm.name,
            industry: detailsForm.industry,
            description: detailsForm.description,
            reply_language: detailsForm.reply_language,
            integration_base_url: detailsForm.integration_base_url,
            integration_api_key: detailsForm.integration_api_key,
            search_enabled: detailsForm.search_enabled,
            order_enabled: detailsForm.order_enabled,
            email_enabled: detailsForm.email_enabled,
            sms_enabled: detailsForm.sms_enabled,
        });
        detailsForm.integration_api_key = '';
        detailsSuccess.value = 'Business updated.';
    } catch (e) {
        if (e.response?.status === 422) {
            detailsForm.setError(e.response.data.errors ? Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]])) : {});
        }
        detailsError.value = e.response?.data?.message || 'Failed to update business.';
    } finally {
        detailsForm.processing = false;
    }
};

// --- AI-drafted description (primary knowledge) ---
const aiNotes = ref('');
const generatingDescription = ref(false);
const generateError = ref('');
const generateDescription = async () => {
    if (!aiNotes.value.trim()) return;
    generateError.value = '';
    generatingDescription.value = true;
    try {
        const { data } = await axios.post(panelRoute('chat-engine.businesses.description.generate', { business: props.business.id }), {
            notes: aiNotes.value,
        });
        detailsForm.description = data.description;
    } catch (e) {
        generateError.value = e.response?.data?.message || e.response?.data?.errors?.notes?.[0] || 'Failed to generate description.';
    } finally {
        generatingDescription.value = false;
    }
};

// --- Channel assignment (AJAX, no page navigation) ---
const assignedChannels = ref([...props.assignedChannels]);
const unassignedChannels = ref([...props.unassignedChannels]);
const selectedChannelId = ref('');
const assignError = ref('');
const assigning = ref(false);
const unassigningId = ref(null);

const assignChannel = async () => {
    if (!selectedChannelId.value) return;
    assignError.value = '';
    assigning.value = true;
    try {
        const { data } = await axios.post(panelRoute('chat-engine.businesses.channels.assign', { business: props.business.id }), {
            channel_id: selectedChannelId.value,
        });
        unassignedChannels.value = unassignedChannels.value.filter((c) => c.id !== data.channel.id);
        assignedChannels.value.push(data.channel);
        selectedChannelId.value = '';
    } catch (e) {
        assignError.value = e.response?.data?.message || e.response?.data?.errors?.channel_id?.[0] || 'Failed to assign channel.';
    } finally {
        assigning.value = false;
    }
};

const unassignChannel = async (channel) => {
    unassigningId.value = channel.id;
    try {
        await axios.delete(panelRoute('chat-engine.businesses.channels.unassign', { business: props.business.id, channel: channel.id }));
        assignedChannels.value = assignedChannels.value.filter((c) => c.id !== channel.id);
        unassignedChannels.value.push(channel);
    } catch (e) {
        assignError.value = e.response?.data?.message || 'Failed to unassign channel.';
    } finally {
        unassigningId.value = null;
    }
};

// --- Products ---
const newProductForm = useForm({ name: '', description: '' });
const addProduct = () => {
    if (!newProductForm.name) return;
    newProductForm.post(panelRoute('chat-engine.businesses.products.store', { business: props.business.id }), {
        preserveScroll: true,
        onSuccess: () => { newProductForm.reset(); },
    });
};
const deleteProductForm = useForm({});
const deleteProduct = (product) => {
    if (!confirm(`Delete product "${product.name}" and all its Q&A?`)) return;
    deleteProductForm.delete(panelRoute('chat-engine.businesses.products.destroy', { business: props.business.id, product: product.id }), { preserveScroll: true });
};

// --- Q&A ---
const newQnaForms = ref({}); // productId -> { question, answer }
const qnaForm = (productId) => {
    if (!newQnaForms.value[productId]) {
        newQnaForms.value[productId] = useForm({ question: '', answer: '' });
    }
    return newQnaForms.value[productId];
};
const addQna = (product) => {
    const form = qnaForm(product.id);
    if (!form.question || !form.answer) return;
    form.post(panelRoute('chat-engine.businesses.qnas.store', { business: props.business.id, product: product.id }), {
        preserveScroll: true,
        onSuccess: () => { form.reset(); },
    });
};
const deleteQnaForm = useForm({});
const deleteQna = (product, qna) => {
    if (!confirm('Delete this Q&A?')) return;
    deleteQnaForm.delete(panelRoute('chat-engine.businesses.qnas.destroy', { business: props.business.id, product: product.id, qna: qna.id }), { preserveScroll: true });
};

// --- AI-suggested Q&A (drafts, reviewed before saving) ---
const suggestions = ref({}); // productId -> [{question, answer}]
const suggestLoading = ref({});
const suggestError = ref({});

const suggestQna = async (product) => {
    suggestError.value[product.id] = '';
    suggestLoading.value[product.id] = true;
    try {
        const { data } = await axios.post(panelRoute('chat-engine.businesses.qnas.suggest', { business: props.business.id, product: product.id }));
        suggestions.value[product.id] = data.suggestions;
    } catch (e) {
        suggestError.value[product.id] = e.response?.data?.message || 'Failed to generate suggestions.';
    } finally {
        suggestLoading.value[product.id] = false;
    }
};

const addSuggestion = (product, index) => {
    const suggestion = suggestions.value[product.id][index];
    router.post(panelRoute('chat-engine.businesses.qnas.store', { business: props.business.id, product: product.id }), suggestion, {
        preserveScroll: true,
        onSuccess: () => { suggestions.value[product.id].splice(index, 1); },
    });
};

const dismissSuggestion = (product, index) => {
    suggestions.value[product.id].splice(index, 1);
};
</script>

<template>
    <Head :title="`Business: ${business.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">{{ business.name }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Products, Q&A training and channel assignment.</p>
                </div>
                <ChatEngineDocsButton title="Businesses — Guide" :sections="['business-training', 'business-live-data', 'troubleshooting']" />
            </div>
        </template>

        <div class="mx-auto space-y-6">
            <transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0">
                <div v-if="page.props.flash?.success" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 shadow-sm dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                    {{ page.props.flash.success }}
                </div>
            </transition>
            <div v-if="page.props.flash?.error" class="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 shadow-sm dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd" /></svg>
                {{ page.props.flash.error }}
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
            <div class="space-y-6 lg:order-2 lg:col-span-1">

            <!-- AI reply usage (billing) -->
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-700">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                        <span class="flex h-6 w-6 items-center justify-center rounded-md bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 2.75a.75.75 0 00-1.5 0V4.5h-1a.75.75 0 000 1.5h1v1.25a.75.75 0 001.5 0V6h1a.75.75 0 000-1.5h-1V2.75zM5.5 8.5a.75.75 0 01.75.75v.5h.5a.75.75 0 010 1.5h-.5v.5a.75.75 0 01-1.5 0v-.5h-.5a.75.75 0 010-1.5h.5v-.5a.75.75 0 01.75-.75zM13 11.75a.75.75 0 00-1.5 0v.5h-.5a.75.75 0 000 1.5h.5v.5a.75.75 0 001.5 0v-.5h.5a.75.75 0 000-1.5H13v-.5z" /></svg>
                        </span>
                        AI reply usage
                    </h2>
                </div>
                <div class="px-5 py-4">
                    <div class="flex items-baseline justify-between">
                        <p class="text-2xl font-semibold text-slate-800 dark:text-slate-100">
                            {{ business.ai_replies_used.toLocaleString() }}
                            <span class="text-base font-normal text-slate-400">
                                {{ business.ai_reply_limit !== null ? `/ ${business.ai_reply_limit.toLocaleString()}` : '· unlimited' }}
                            </span>
                        </p>
                        <span
                            v-if="business.ai_reply_limit !== null"
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="business.ai_replies_used >= business.ai_reply_limit ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300'"
                        >
                            {{ business.ai_replies_used >= business.ai_reply_limit ? 'Exhausted' : 'Active' }}
                        </span>
                    </div>

                    <div v-if="business.ai_reply_limit !== null" class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                        <div
                            class="h-full rounded-full transition-all"
                            :class="business.ai_replies_used >= business.ai_reply_limit ? 'bg-red-500' : 'bg-violet-500'"
                            :style="{ width: Math.min(100, (business.ai_replies_used / Math.max(business.ai_reply_limit, 1)) * 100) + '%' }"
                        ></div>
                    </div>

                    <p class="mt-3 text-xs text-slate-400">
                        Counted from every AI reply this business's channels send — use it to bill the client. The cap comes from the owner's package plan; when reached, auto-reply pauses until the package is upgraded or the owner is switched to a higher plan.
                    </p>
                    <p v-if="business.ai_reply_limit !== null && business.ai_replies_used >= business.ai_reply_limit" class="mt-3 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                        Reply credit exhausted — auto-reply is currently paused for this business.
                    </p>
                </div>
            </section>

            <!-- Assigned channels -->
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Assigned channels</h2>
                    <p class="mt-0.5 text-xs text-slate-400">Which chat channels this business's knowledge and settings apply to.</p>
                </div>
                <div class="px-5 py-4">

                <p v-if="assignError" class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ assignError }}</p>

                <div v-if="assignedChannels.length" class="mb-4 flex flex-wrap gap-2">
                    <div v-for="c in assignedChannels" :key="c.id" class="flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 py-1 pl-3 pr-1.5 text-sm dark:border-slate-600 dark:bg-slate-900/40">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        <span>{{ c.name }}</span>
                        <span class="text-xs capitalize text-slate-400">{{ c.type }}</span>
                        <button @click="unassignChannel(c)" :disabled="unassigningId === c.id" class="ml-1 rounded-full p-1 text-slate-400 transition hover:bg-red-100 hover:text-red-600 disabled:opacity-50 dark:hover:bg-red-900/30" title="Unassign">
                            <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                        </button>
                    </div>
                </div>
                <p v-else class="mb-4 text-sm text-slate-500 dark:text-slate-400">No channels assigned yet — the AI will use each channel's own generic system prompt until one is assigned here.</p>

                <div v-if="unassignedChannels.length" class="flex items-center gap-2">
                    <select v-model="selectedChannelId" class="flex-1 rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900">
                        <option value="">Select a channel to assign…</option>
                        <option v-for="c in unassignedChannels" :key="c.id" :value="c.id">{{ c.name }} ({{ c.type }})</option>
                    </select>
                    <button @click="assignChannel" :disabled="!selectedChannelId || assigning" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50">Assign</button>
                </div>
                <p v-else-if="!assignedChannels.length" class="text-xs text-slate-400">All your channels are already assigned to a business, or none exist yet.</p>
                </div>
            </section>

            </div>

            <div class="space-y-6 lg:order-1 lg:col-span-2">

            <!-- Business details -->
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Business details</h2>
                    <p class="mt-0.5 text-xs text-slate-400">What the AI knows about the business, and how it should sound.</p>
                </div>
                <div class="px-5 py-4">
                <p v-if="detailsSuccess" class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">{{ detailsSuccess }}</p>
                <p v-if="detailsError" class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ detailsError }}</p>
                <form @submit.prevent="saveDetails" class="space-y-4 text-slate-800 dark:text-slate-100">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Name</label>
                            <input v-model="detailsForm.name" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Industry</label>
                            <input v-model="detailsForm.industry" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Description</label>
                        <p class="mb-2 text-xs text-slate-400">This is the AI's primary knowledge about the business — who it is, what it does, and how to contact it. It's sent to the AI on every reply, so keep it accurate.</p>
                        <textarea v-model="detailsForm.description" rows="4" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900"></textarea>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Reply language (optional)</label>
                        <p class="mb-2 text-xs text-slate-400">When set, the AI always replies in this language, no matter what language the customer writes in. Leave blank to let it match the customer's own language.</p>
                        <input v-model="detailsForm.reply_language" type="text" placeholder="e.g. Bengali, English, Hindi" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900" />
                    </div>

                    <div class="rounded-lg border border-dashed border-violet-300 bg-violet-50/40 p-4 dark:border-violet-800 dark:bg-violet-900/10">
                        <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-violet-700 dark:text-violet-300">✨ Write it with AI</label>
                        <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Give a few raw notes — who you are, what you offer, contact details (phone/email/address/hours) — and AI will turn it into the description above.</p>
                        <textarea v-model="aiNotes" rows="3" placeholder="e.g. We are Acme Bakery, a home-delivery cake shop in Dhaka. We make wedding cakes, cupcakes and custom orders. Call us at 01XXXXXXXXX or email hello@acmebakery.com. Open 9am-9pm every day." class="w-full rounded-md border-slate-300 bg-white text-sm shadow-sm transition focus:border-violet-500 focus:ring-1 focus:ring-violet-500 dark:border-slate-600 dark:bg-slate-900"></textarea>
                        <p v-if="generateError" class="mt-1 text-xs text-red-600">{{ generateError }}</p>
                        <div class="mt-2 flex justify-end">
                            <button type="button" @click="generateDescription" :disabled="!aiNotes.trim() || generatingDescription" class="rounded-md border border-violet-300 bg-white px-3 py-1.5 text-xs font-medium text-violet-700 shadow-sm transition hover:bg-violet-100 disabled:opacity-50 dark:border-violet-700 dark:bg-slate-800 dark:text-violet-300 dark:hover:bg-violet-900/30">
                                {{ generatingDescription ? 'Generating…' : 'Generate description' }}
                            </button>
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Client integration (optional)</label>
                        <p class="mb-2 text-xs text-slate-400">
                            One endpoint, implemented on your own site, covers everything below — click "Docs" above for the exact contract.
                            We POST <code class="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-900">{"tool": "search"|"place_order"|"send_email"|"send_sms", ...}</code> as JSON to this one URL. <code class="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-900">search</code> takes multiple <code class="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-900">queries</code> and expects back <code class="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-900">{"results": [{"title","link","description"}, ...]}</code>; the other tools expect back <code class="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-900">{"result": "..."}</code>.
                        </p>
                        <input v-model="detailsForm.integration_base_url" type="url" placeholder="https://yoursite.com/api/dpanel-integration" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900" />
                        <p v-if="detailsForm.errors.integration_base_url" class="mt-1 text-xs text-red-600">{{ detailsForm.errors.integration_base_url }}</p>
                        <input v-model="detailsForm.integration_api_key" type="password" :placeholder="business.has_integration_api_key ? 'Leave blank to keep current key' : 'API key (optional, sent as Bearer token)'" class="mt-2 w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900" />

                        <p class="mb-2 mt-4 text-xs font-medium text-slate-500 dark:text-slate-400">Enabled capabilities</p>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <label class="flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm transition has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50 dark:border-slate-600 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-900/20">
                                <input v-model="detailsForm.search_enabled" type="checkbox" class="rounded border-slate-300 text-blue-600" /> Search
                            </label>
                            <label class="flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm transition has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50 dark:border-slate-600 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-900/20">
                                <input v-model="detailsForm.order_enabled" type="checkbox" class="rounded border-slate-300 text-blue-600" /> Order
                            </label>
                            <label class="flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm transition has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50 dark:border-slate-600 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-900/20">
                                <input v-model="detailsForm.email_enabled" type="checkbox" class="rounded border-slate-300 text-blue-600" /> Email
                            </label>
                            <label class="flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm transition has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50 dark:border-slate-600 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-900/20">
                                <input v-model="detailsForm.sms_enabled" type="checkbox" class="rounded border-slate-300 text-blue-600" /> SMS
                            </label>
                        </div>
                        <p v-if="!detailsForm.integration_base_url" class="mt-2 text-xs text-amber-600 dark:text-amber-400">Set a URL above to enable any of these — a capability only becomes active when both the URL is set and its box is checked.</p>
                    </div>

                    <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-slate-700">
                        <button type="submit" :disabled="detailsForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50">
                            {{ detailsForm.processing ? 'Saving…' : 'Save changes' }}
                        </button>
                    </div>
                </form>
                </div>
            </section>

            <!-- Products & Q&A -->
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Products & Q&A (training data)</h2>
                    <p class="mt-0.5 text-xs text-slate-400">The AI answers assigned-channel conversations only from this data, and declines instead of guessing when a question isn't covered.</p>
                </div>
                <div class="px-5 py-4">

                <div class="space-y-4">
                    <div v-for="product in products" :key="product.id" class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <div class="mb-2 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <p class="font-medium text-slate-800 dark:text-slate-100">{{ product.name }}</p>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-slate-700 dark:text-slate-300">{{ product.qnas.length }} Q&A</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <button @click="suggestQna(product)" :disabled="suggestLoading[product.id]" class="text-xs font-medium text-violet-600 hover:underline disabled:opacity-50 dark:text-violet-400">
                                    {{ suggestLoading[product.id] ? 'Thinking…' : '✨ Suggest Q&A' }}
                                </button>
                                <button @click="deleteProduct(product)" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400">Delete product</button>
                            </div>
                        </div>
                        <p v-if="product.description" class="mb-3 text-xs text-slate-500 dark:text-slate-400">{{ product.description }}</p>
                        <p v-if="suggestError[product.id]" class="mb-2 text-xs text-red-600">{{ suggestError[product.id] }}</p>

                        <div v-if="suggestions[product.id]?.length" class="mb-3 space-y-2">
                            <div v-for="(s, i) in suggestions[product.id]" :key="i" class="rounded-md border border-dashed border-violet-300 bg-violet-50 p-3 text-sm dark:border-violet-800 dark:bg-violet-900/20">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-medium">Q: {{ s.question }}</p>
                                        <p class="mt-1 text-slate-600 dark:text-slate-300">A: {{ s.answer }}</p>
                                    </div>
                                    <div class="flex shrink-0 gap-2 text-xs font-medium">
                                        <button @click="addSuggestion(product, i)" class="text-violet-600 hover:underline dark:text-violet-400">Add</button>
                                        <button @click="dismissSuggestion(product, i)" class="text-slate-400 hover:underline">Dismiss</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <div v-for="qna in product.qnas" :key="qna.id" class="rounded-md bg-slate-50 p-3 text-sm dark:bg-slate-900/40">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-medium">Q: {{ qna.question }}</p>
                                        <p class="mt-1 text-slate-600 dark:text-slate-300">A: {{ qna.answer }}</p>
                                    </div>
                                    <button @click="deleteQna(product, qna)" class="shrink-0 text-xs font-medium text-red-600 hover:underline dark:text-red-400">Delete</button>
                                </div>
                            </div>
                            <p v-if="!product.qnas.length" class="text-xs text-slate-400">No Q&A yet for this product.</p>
                        </div>

                        <div class="mt-3 space-y-2 border-t border-slate-100 pt-3 dark:border-slate-700">
                            <input v-model="qnaForm(product.id).question" type="text" placeholder="Question" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900" />
                            <textarea v-model="qnaForm(product.id).answer" rows="2" placeholder="Answer" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900"></textarea>
                            <div class="flex justify-end">
                                <button @click="addQna(product)" :disabled="qnaForm(product.id).processing" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium shadow-sm transition hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">+ Add Q&A</button>
                            </div>
                        </div>
                    </div>

                    <p v-if="!products.length" class="text-sm text-slate-500 dark:text-slate-400">No products yet.</p>
                </div>

                <div class="mt-4 space-y-2 rounded-lg border border-dashed border-slate-300 p-4 dark:border-slate-600">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Add a product</p>
                    <input v-model="newProductForm.name" type="text" placeholder="Product name" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900" />
                    <textarea v-model="newProductForm.description" rows="2" placeholder="Description (optional)" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900"></textarea>
                    <div class="flex justify-end">
                        <button @click="addProduct" :disabled="!newProductForm.name || newProductForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-xs font-medium text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50">+ Add Product</button>
                    </div>
                </div>
                </div>
            </section>

            </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
