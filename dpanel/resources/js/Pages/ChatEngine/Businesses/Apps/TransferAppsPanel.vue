<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { appType, usePanelRoute } from '../shared.js';

const props = defineProps({
    business: { type: Object, required: true },
    apps: { type: Array, required: true },
    otherBusinesses: { type: Array, default: () => [] },
    // 'transfer' = move to another business, 'detach' = leave every business.
    mode: { type: String, default: 'transfer' },
});

const emit = defineEmits(['done', 'cancel']);

const panelRoute = usePanelRoute();
const target = ref('');
const search = ref('');
const processing = ref(false);
const error = ref('');

const filteredBusinesses = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.otherBusinesses.filter((b) => !needle || b.name.toLowerCase().includes(needle));
});

const targetName = computed(() => props.otherBusinesses.find((b) => b.id === target.value)?.name);
const canSubmit = computed(() => !processing.value && (props.mode === 'detach' || !!target.value));

const submit = () => {
    if (!canSubmit.value) return;
    error.value = '';
    processing.value = true;
    router.post(panelRoute('chat-engine.businesses.apps.transfer', { business: props.business.id }), {
        channel_ids: props.apps.map((a) => a.id),
        target_business_id: props.mode === 'detach' ? null : target.value,
    }, {
        preserveScroll: true,
        onSuccess: () => emit('done'),
        onError: (errors) => { error.value = Object.values(errors)[0] || 'Transfer failed.'; },
        onFinish: () => { processing.value = false; },
    });
};
</script>

<template>
    <div class="space-y-5">
        <div>
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">{{ apps.length === 1 ? 'App' : `${apps.length} apps` }}</p>
            <div class="max-h-48 space-y-1.5 overflow-y-auto">
                <div v-for="a in apps" :key="a.id" class="flex items-center gap-2.5 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">
                    <span class="flex h-7 w-7 items-center justify-center rounded-md text-sm" :class="appType(a.type).tint"><i :class="['bi', appType(a.type).icon]"></i></span>
                    <span class="min-w-0 flex-1 truncate font-medium">{{ a.name }}</span>
                    <span class="text-xs text-slate-400">{{ (a.conversations_count ?? 0).toLocaleString() }} chats</span>
                </div>
            </div>
        </div>

        <template v-if="mode === 'transfer'">
            <div>
                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Move to</p>
                <input v-if="otherBusinesses.length > 6" v-model="search" type="search" placeholder="Search businesses…" class="mb-2 w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <div class="max-h-72 space-y-1.5 overflow-y-auto">
                    <label
                        v-for="b in filteredBusinesses"
                        :key="b.id"
                        class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm transition"
                        :class="target === b.id ? 'border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-900/20' : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800'"
                    >
                        <input v-model="target" type="radio" :value="b.id" class="border-slate-300 text-blue-600" />
                        <span class="flex h-7 w-7 items-center justify-center rounded-md bg-slate-100 text-xs font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ b.name.charAt(0).toUpperCase() }}</span>
                        <span class="font-medium">{{ b.name }}</span>
                    </label>
                    <p v-if="!filteredBusinesses.length" class="py-4 text-center text-sm text-slate-500">No other business found.</p>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-300">
                <p class="mb-1 font-medium">What moves with the app</p>
                <ul class="list-disc space-y-0.5 pl-4">
                    <li>All contacts, conversations and scheduled messages — nothing is deleted.</li>
                    <li>From the next message on, the AI answers with <strong>{{ targetName || 'the new business' }}</strong>'s profile, products, Q&A and tools.</li>
                    <li>Ownership follows the business, so its owner can manage the app. Reply credit is counted on the new business.</li>
                </ul>
            </div>
        </template>

        <div v-else class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
            Detached apps keep running and keep their chat history, but the AI stops using <strong>{{ business.name }}</strong>'s knowledge and falls back to the app's own system prompt. You can attach them again from any business's Apps tab.
        </div>

        <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ error }}</p>

        <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
            <button type="button" @click="emit('cancel')" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-800">Cancel</button>
            <button
                type="button"
                @click="submit"
                :disabled="!canSubmit"
                class="rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition disabled:opacity-50"
                :class="mode === 'detach' ? 'bg-amber-600 hover:bg-amber-700' : 'bg-blue-600 hover:bg-blue-700'"
            >
                {{ processing ? 'Working…' : mode === 'detach' ? `Detach ${apps.length === 1 ? 'app' : apps.length + ' apps'}` : `Transfer ${apps.length === 1 ? 'app' : apps.length + ' apps'}` }}
            </button>
        </div>
    </div>
</template>
