<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import { usePanelRoute } from '../shared.js';

const props = defineProps({
    business: { type: Object, required: true },
});

const emit = defineEmits(['saved']);

const panelRoute = usePanelRoute();

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
        emit('saved');
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
</script>

<template>
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
</template>
