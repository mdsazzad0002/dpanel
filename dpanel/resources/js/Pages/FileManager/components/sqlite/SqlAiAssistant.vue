<script setup>
import { ref } from 'vue';

const props = defineProps({
    // (prompt) => Promise<string>: the generated SQL.
    ask: { type: Function, required: true },
});

const emit = defineEmits(['generated']);

const prompt = ref('');
const busy = ref(false);
const error = ref('');

async function generate() {
    const text = prompt.value.trim();
    if (!text || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        emit('generated', await props.ask(text));
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

function onKey(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        generate();
    }
}
</script>

<template>
    <div class="rounded-lg border border-violet-200 bg-violet-50/60 p-2 dark:border-violet-900/60 dark:bg-violet-950/20">
        <div class="flex items-start gap-2">
            <i class="bi bi-stars mt-1.5 text-violet-500"></i>
            <textarea
                v-model="prompt"
                rows="1"
                :disabled="busy"
                placeholder="Ask AI: e.g. last 10 users who signed up this month / gmail user der list dao"
                class="min-h-[2.1rem] flex-1 resize-y rounded-md border border-violet-200 bg-white px-2 py-1.5 text-xs outline-none focus:border-violet-400 dark:border-violet-900 dark:bg-slate-900"
                @keydown="onKey"
            ></textarea>
            <button
                type="button"
                :disabled="busy || !prompt.trim()"
                class="shrink-0 rounded-md bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-700 disabled:opacity-50"
                @click="generate"
            >
                <i class="bi" :class="busy ? 'bi-arrow-repeat inline-block animate-spin' : 'bi-magic'"></i>
                {{ busy ? 'Writing…' : 'Generate SQL' }}
            </button>
        </div>
        <p v-if="error" class="mt-1.5 pl-6 text-xs text-red-600 dark:text-red-400">{{ error }}</p>
        <p v-else class="mt-1 pl-6 text-[11px] text-slate-500">The SQL goes into the editor below. Check it, then press Run — nothing runs on its own.</p>
    </div>
</template>
