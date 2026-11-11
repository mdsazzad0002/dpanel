<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/composables/usePanelApi';
import ManifestReview from './ManifestReview.vue';

// Paste or pick a manifest, check it, then review what it asks for (U7).
defineProps({
    events: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved']);
const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);

const text = ref('');
const review = ref(null);
const busy = ref(false);
const problems = ref([]);
const error = ref('');

const pick = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    file.text().then((content) => {
        text.value = content;
        review.value = null;
    });
};

const check = async () => {
    busy.value = true;
    problems.value = [];
    error.value = '';
    review.value = null;
    try {
        const { data } = await window.axios.post(panelRoute('system.extensions.review'), { manifest: text.value });
        review.value = data;
    } catch (e) {
        const listed = e?.response?.data?.errors?.manifest;
        if (Array.isArray(listed)) problems.value = listed;
        else error.value = errorMessage(e, t('extensions.reviewFailed'));
    } finally {
        busy.value = false;
    }
};

const done = (result) => {
    emit('saved', result);
    text.value = '';
    review.value = null;
};
</script>

<template>
    <section class="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div>
            <h2 class="text-sm font-semibold">{{ t('extensions.add') }}</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('extensions.addHelp') }}</p>
        </div>
        <textarea v-model="text" rows="8" spellcheck="false" :placeholder="t('extensions.manifestPlaceholder')" class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800" @input="review = null" />
        <div class="flex flex-wrap items-center gap-2">
            <label class="cursor-pointer rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                {{ t('extensions.chooseFile') }}
                <input type="file" accept="application/json,.json" class="hidden" @change="pick" />
            </label>
            <button type="button" :disabled="busy || !text.trim()" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50" @click="check">{{ t('extensions.check') }}</button>
            <span class="text-xs text-slate-500">{{ t('extensions.eventsKnown', { events: events.join(', ') }) }}</span>
        </div>
        <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
        <div v-if="problems.length" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
            <p class="font-semibold">{{ t('extensions.problems') }}</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5 font-mono text-xs">
                <li v-for="problem in problems" :key="problem">{{ problem }}</li>
            </ul>
        </div>
        <ManifestReview v-if="review" :review="review" :manifest-text="text" @saved="done" @cancel="review = null" />
    </section>
</template>
