<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// "Describe the job in plain words": the AI fills the schedule, name and,
// when a task is described, the command. The user checks it and saves.
const props = defineProps({
    endpoint: { type: String, required: true },
    // The command already in the form, so the AI keeps it.
    command: { type: String, default: '' },
});

const emit = defineEmits(['fill']);

const request = ref('');
const loading = ref(false);
const error = ref('');
const explanation = ref('');

const run = async () => {
    if (loading.value || request.value.trim() === '') return;
    loading.value = true;
    error.value = '';
    explanation.value = '';
    try {
        const { data } = await window.axios.post(props.endpoint, { request: request.value, command: props.command }, { headers: { Accept: 'application/json' } });
        explanation.value = data.explanation || '';
        emit('fill', { expression: data.expression, name: data.name, command: data.command });
    }
    catch (e) {
        error.value = e?.response?.data?.message || e?.message || t('websites.cron.ai.failed');
    }
    finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="rounded-lg border border-violet-200 bg-violet-50/60 p-3 dark:border-violet-500/30 dark:bg-violet-500/10">
        <label for="cron-ai-request" class="mb-1 flex items-center gap-1.5 text-sm font-medium text-violet-800 dark:text-violet-200">
            <i class="bi bi-stars"></i>{{ t('websites.cron.ai.label') }}
        </label>
        <div class="flex flex-col gap-2 sm:flex-row">
            <input
                id="cron-ai-request"
                v-model="request"
                type="text"
                maxlength="1000"
                :placeholder="t('websites.cron.ai.placeholder')"
                class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                @keydown.enter.prevent="run"
            />
            <button
                type="button"
                :disabled="loading || request.trim() === ''"
                class="inline-flex items-center justify-center gap-1.5 rounded-md bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700 disabled:opacity-60"
                @click="run"
            >
                <i :class="['bi', loading ? 'bi-arrow-repeat animate-spin' : 'bi-stars']"></i>
                {{ loading ? t('websites.cron.ai.working') : t('websites.cron.ai.fill') }}
            </button>
        </div>
        <p v-if="error" class="mt-2 text-xs text-red-600">{{ error }}</p>
        <p v-else-if="explanation" class="mt-2 text-xs text-slate-600 dark:text-slate-300">
            <i class="bi bi-check2-circle mr-1 text-emerald-600"></i>{{ explanation }} {{ t('websites.cron.ai.check') }}
        </p>
    </div>
</template>
