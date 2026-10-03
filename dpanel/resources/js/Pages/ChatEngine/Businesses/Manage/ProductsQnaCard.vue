<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import { usePanelRoute } from '../shared.js';

const props = defineProps({
    business: { type: Object, required: true },
    products: { type: Array, default: () => [] },
});

const panelRoute = usePanelRoute();

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

// --- Inline Q&A editing ---
const editingQna = ref(null); // { id, productId, question, answer, processing }
const startEditQna = (product, qna) => {
    editingQna.value = { id: qna.id, productId: product.id, question: qna.question, answer: qna.answer, processing: false };
};
const saveQna = () => {
    const edit = editingQna.value;
    if (!edit?.question || !edit?.answer) return;
    edit.processing = true;
    router.patch(panelRoute('chat-engine.businesses.qnas.update', { business: props.business.id, product: edit.productId, qna: edit.id }), {
        question: edit.question,
        answer: edit.answer,
    }, {
        preserveScroll: true,
        onSuccess: () => { editingQna.value = null; },
        onFinish: () => { if (editingQna.value) editingQna.value.processing = false; },
    });
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
                        <div v-if="editingQna?.id === qna.id" class="space-y-2">
                            <input v-model="editingQna.question" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900" />
                            <textarea v-model="editingQna.answer" rows="3" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900"></textarea>
                            <div class="flex justify-end gap-2">
                                <button @click="editingQna = null" class="rounded-md px-3 py-1.5 text-xs text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-700">Cancel</button>
                                <button @click="saveQna" :disabled="editingQna.processing || !editingQna.question || !editingQna.answer" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50">{{ editingQna.processing ? 'Saving…' : 'Save' }}</button>
                            </div>
                        </div>
                        <div v-else class="flex items-start justify-between gap-2">
                            <div>
                                <p class="font-medium">Q: {{ qna.question }}</p>
                                <p class="mt-1 whitespace-pre-line text-slate-600 dark:text-slate-300">A: {{ qna.answer }}</p>
                            </div>
                            <div class="flex shrink-0 gap-3">
                                <button @click="startEditQna(product, qna)" class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">Edit</button>
                                <button @click="deleteQna(product, qna)" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400">Delete</button>
                            </div>
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
</template>
