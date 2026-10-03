<script setup>
import AiUsageCard from './AiUsageCard.vue';
import BusinessDetailsCard from './BusinessDetailsCard.vue';
import ProductsQnaCard from './ProductsQnaCard.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { usePanelRoute } from '../shared.js';

const props = defineProps({
    business: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
});

const panelRoute = usePanelRoute();

const confirmName = ref('');
const deleting = ref(false);
const destroy = () => {
    if (confirmName.value !== props.business.name) return;
    deleting.value = true;
    router.delete(panelRoute('chat-engine.businesses.destroy', { business: props.business.id }), {
        onFinish: () => { deleting.value = false; },
    });
};
</script>

<template>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <div class="space-y-6 lg:order-2 lg:col-span-1">
            <AiUsageCard :business="business" />

            <!-- Knowledge health: quick checklist of what the AI has to work with -->
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">AI readiness</h2>
                    <p class="mt-0.5 text-xs text-slate-400">What the AI can use when it replies.</p>
                </div>
                <ul class="space-y-2.5 px-5 py-4 text-sm">
                    <li class="flex items-center gap-2">
                        <i class="bi" :class="business.description ? 'bi-check-circle-fill text-emerald-500' : 'bi-circle text-slate-300'"></i>
                        Business description
                    </li>
                    <li class="flex items-center gap-2">
                        <i class="bi" :class="products.length ? 'bi-check-circle-fill text-emerald-500' : 'bi-circle text-slate-300'"></i>
                        {{ products.length }} product{{ products.length === 1 ? '' : 's' }}, {{ products.reduce((n, p) => n + p.qnas.length, 0) }} Q&A
                    </li>
                    <li class="flex items-center gap-2">
                        <i class="bi" :class="business.reply_language ? 'bi-check-circle-fill text-emerald-500' : 'bi-dash-circle text-slate-300'"></i>
                        Reply language: {{ business.reply_language || 'matches the customer' }}
                    </li>
                    <li class="flex items-center gap-2">
                        <i class="bi" :class="business.integration_base_url ? 'bi-check-circle-fill text-emerald-500' : 'bi-dash-circle text-slate-300'"></i>
                        Live tools: {{ business.integration_base_url ? [business.search_enabled && 'Search', business.order_enabled && 'Order', business.email_enabled && 'Email', business.sms_enabled && 'SMS'].filter(Boolean).join(', ') || 'none enabled' : 'not connected' }}
                    </li>
                    <li class="flex items-center gap-2">
                        <i class="bi" :class="stats.active_apps ? 'bi-check-circle-fill text-emerald-500' : 'bi-circle text-slate-300'"></i>
                        {{ stats.active_apps }} live app{{ stats.active_apps === 1 ? '' : 's' }}
                    </li>
                </ul>
            </section>

            <!-- Danger zone -->
            <section class="overflow-hidden rounded-xl border border-red-200 bg-white shadow-sm dark:border-red-900/60 dark:bg-slate-800">
                <div class="border-b border-red-100 px-5 py-4 dark:border-red-900/60">
                    <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">Delete business</h2>
                    <p class="mt-0.5 text-xs text-slate-400">Removes its products and Q&A. Its apps are kept, just detached — move them first if they should answer for another business.</p>
                </div>
                <div class="space-y-2 px-5 py-4">
                    <label class="block text-xs text-slate-500 dark:text-slate-400">Type <span class="font-semibold text-slate-700 dark:text-slate-200">{{ business.name }}</span> to confirm</label>
                    <input v-model="confirmName" type="text" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <button
                        type="button"
                        @click="destroy"
                        :disabled="confirmName !== business.name || deleting"
                        class="w-full rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-red-700 disabled:opacity-40"
                    >{{ deleting ? 'Deleting…' : 'Delete business' }}</button>
                </div>
            </section>
        </div>

        <div class="space-y-6 lg:order-1 lg:col-span-2">
            <BusinessDetailsCard :business="business" @saved="router.reload({ only: ['business'], preserveScroll: true })" />
            <ProductsQnaCard :business="business" :products="products" />
        </div>
    </div>
</template>
