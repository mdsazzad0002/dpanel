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
    integration_base_url: props.business.integration_base_url || '',
    integration_api_key: '',
    search_enabled: props.business.search_enabled,
    order_enabled: props.business.order_enabled,
    email_enabled: props.business.email_enabled,
    sms_enabled: props.business.sms_enabled,
});
const saveDetails = () => {
    detailsForm.patch(panelRoute('chat-engine.businesses.update', { business: props.business.id }), { preserveScroll: true });
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

        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <!-- Business details -->
            <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="mb-3 text-sm font-semibold">Business details</h2>
                <form @submit.prevent="saveDetails" class="space-y-3 text-slate-800 dark:text-slate-100">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Name</label>
                            <input v-model="detailsForm.name" type="text" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Industry</label>
                            <input v-model="detailsForm.industry" type="text" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Description</label>
                        <p class="mb-2 text-xs text-slate-400">This is the AI's primary knowledge about the business — who it is, what it does, and how to contact it. It's sent to the AI on every reply, so keep it accurate.</p>
                        <textarea v-model="detailsForm.description" rows="4" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                    </div>

                    <div class="rounded-md border border-dashed border-slate-300 p-3 dark:border-slate-600">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">✨ Write it with AI</label>
                        <p class="mb-2 text-xs text-slate-400">Give a few raw notes — who you are, what you offer, contact details (phone/email/address/hours) — and AI will turn it into the description above.</p>
                        <textarea v-model="aiNotes" rows="3" placeholder="e.g. We are Acme Bakery, a home-delivery cake shop in Dhaka. We make wedding cakes, cupcakes and custom orders. Call us at 01XXXXXXXXX or email hello@acmebakery.com. Open 9am-9pm every day." class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                        <p v-if="generateError" class="mt-1 text-xs text-red-600">{{ generateError }}</p>
                        <div class="mt-2 flex justify-end">
                            <button type="button" @click="generateDescription" :disabled="!aiNotes.trim() || generatingDescription" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">
                                {{ generatingDescription ? 'Generating…' : 'Generate description' }}
                            </button>
                        </div>
                    </div>

                    <div class="rounded-md border border-slate-200 p-3 dark:border-slate-700">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Client integration (optional)</label>
                        <p class="mb-2 text-xs text-slate-400">
                            One endpoint, implemented on your own site, covers everything below — click "Docs" above for the exact contract.
                            We POST <code>{"tool": "search"|"place_order"|"send_email"|"send_sms", ...}</code> as JSON to this one URL and expect back <code>{"result": "..."}</code>.
                        </p>
                        <input v-model="detailsForm.integration_base_url" type="url" placeholder="https://yoursite.com/api/dpanel-integration" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                        <p v-if="detailsForm.errors.integration_base_url" class="mt-1 text-xs text-red-600">{{ detailsForm.errors.integration_base_url }}</p>
                        <input v-model="detailsForm.integration_api_key" type="password" :placeholder="business.has_integration_api_key ? 'Leave blank to keep current key' : 'API key (optional, sent as Bearer token)'" class="mt-2 w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />

                        <p class="mb-1 mt-3 text-xs font-medium text-slate-500 dark:text-slate-400">Enabled capabilities</p>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="detailsForm.search_enabled" type="checkbox" class="rounded border-slate-300" /> Search
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="detailsForm.order_enabled" type="checkbox" class="rounded border-slate-300" /> Order
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="detailsForm.email_enabled" type="checkbox" class="rounded border-slate-300" /> Email
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="detailsForm.sms_enabled" type="checkbox" class="rounded border-slate-300" /> SMS
                            </label>
                        </div>
                        <p v-if="!detailsForm.integration_base_url" class="mt-2 text-xs text-amber-600">Set a URL above to enable any of these — a capability only becomes active when both the URL is set and its box is checked.</p>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" :disabled="detailsForm.processing" class="rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Save</button>
                    </div>
                </form>
            </div>

            <!-- Assigned channels -->
            <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="mb-3 text-sm font-semibold">Assigned channels</h2>

                <p v-if="assignError" class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ assignError }}</p>

                <div v-if="assignedChannels.length" class="mb-3 space-y-2">
                    <div v-for="c in assignedChannels" :key="c.id" class="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">
                        <span>{{ c.name }} <span class="text-xs capitalize text-slate-400">({{ c.type }})</span></span>
                        <button @click="unassignChannel(c)" :disabled="unassigningId === c.id" class="text-xs text-red-600 hover:underline disabled:opacity-50">Unassign</button>
                    </div>
                </div>
                <p v-else class="mb-3 text-sm text-slate-500 dark:text-slate-400">No channels assigned yet — the AI will use each channel's own generic system prompt until one is assigned here.</p>

                <div v-if="unassignedChannels.length" class="flex items-center gap-2">
                    <select v-model="selectedChannelId" class="flex-1 rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">Select a channel to assign…</option>
                        <option v-for="c in unassignedChannels" :key="c.id" :value="c.id">{{ c.name }} ({{ c.type }})</option>
                    </select>
                    <button @click="assignChannel" :disabled="!selectedChannelId || assigning" class="rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Assign</button>
                </div>
                <p v-else-if="!assignedChannels.length" class="text-xs text-slate-400">All your channels are already assigned to a business, or none exist yet.</p>
            </div>

            <!-- Products & Q&A -->
            <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="mb-1 text-sm font-semibold">Products & Q&A (training data)</h2>
                <p class="mb-4 text-xs text-slate-500 dark:text-slate-400">The AI answers assigned-channel conversations only from this data, and declines instead of guessing when a question isn't covered.</p>

                <div class="space-y-4">
                    <div v-for="product in products" :key="product.id" class="rounded-md border border-slate-200 p-4 dark:border-slate-700">
                        <div class="mb-2 flex items-center justify-between">
                            <p class="font-medium">{{ product.name }}</p>
                            <div class="flex items-center gap-3">
                                <button @click="suggestQna(product)" :disabled="suggestLoading[product.id]" class="text-xs text-blue-600 hover:underline disabled:opacity-50">
                                    {{ suggestLoading[product.id] ? 'Thinking…' : '✨ Suggest Q&A' }}
                                </button>
                                <button @click="deleteProduct(product)" class="text-xs text-red-600 hover:underline">Delete product</button>
                            </div>
                        </div>
                        <p v-if="product.description" class="mb-3 text-xs text-slate-500 dark:text-slate-400">{{ product.description }}</p>
                        <p v-if="suggestError[product.id]" class="mb-2 text-xs text-red-600">{{ suggestError[product.id] }}</p>

                        <div v-if="suggestions[product.id]?.length" class="mb-3 space-y-2">
                            <div v-for="(s, i) in suggestions[product.id]" :key="i" class="rounded-md border border-dashed border-blue-300 bg-blue-50 p-3 text-sm dark:border-blue-800 dark:bg-blue-900/20">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-medium">Q: {{ s.question }}</p>
                                        <p class="mt-1 text-slate-600 dark:text-slate-300">A: {{ s.answer }}</p>
                                    </div>
                                    <div class="flex shrink-0 gap-2 text-xs">
                                        <button @click="addSuggestion(product, i)" class="text-blue-600 hover:underline">Add</button>
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
                                    <button @click="deleteQna(product, qna)" class="shrink-0 text-xs text-red-600 hover:underline">Delete</button>
                                </div>
                            </div>
                            <p v-if="!product.qnas.length" class="text-xs text-slate-400">No Q&A yet for this product.</p>
                        </div>

                        <div class="mt-3 space-y-2 border-t border-slate-200 pt-3 dark:border-slate-700">
                            <input v-model="qnaForm(product.id).question" type="text" placeholder="Question" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                            <textarea v-model="qnaForm(product.id).answer" rows="2" placeholder="Answer" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                            <div class="flex justify-end">
                                <button @click="addQna(product)" :disabled="qnaForm(product.id).processing" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">+ Add Q&A</button>
                            </div>
                        </div>
                    </div>

                    <p v-if="!products.length" class="text-sm text-slate-500 dark:text-slate-400">No products yet.</p>
                </div>

                <div class="mt-4 space-y-2 rounded-md border border-dashed border-slate-300 p-4 dark:border-slate-600">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Add a product</p>
                    <input v-model="newProductForm.name" type="text" placeholder="Product name" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <textarea v-model="newProductForm.description" rows="2" placeholder="Description (optional)" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                    <div class="flex justify-end">
                        <button @click="addProduct" :disabled="!newProductForm.name || newProductForm.processing" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50">+ Add Product</button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
