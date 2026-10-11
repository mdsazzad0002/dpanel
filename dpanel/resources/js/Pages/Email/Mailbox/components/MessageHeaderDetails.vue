<script setup>
import { computed, ref, watch } from 'vue';

// Header details shown under the sender and recipient, the way Gmail's
// "show details" works. They come from the message source, which the
// parent loads on demand, so opening this asks for it.
const props = defineProps({
    analysis: {
        type: Object,
        default: null,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    messageKey: {
        type: String,
        default: '',
    },
    ui: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['load']);

const open = ref(false);
const showAllHeaders = ref(false);

watch(() => props.messageKey, () => {
    open.value = false;
    showAllHeaders.value = false;
});

const toggle = () => {
    open.value = !open.value;
    if (open.value && !props.analysis) emit('load');
};

const rows = computed(() => {
    const summary = props.analysis?.summary || {};

    return [
        ['From', summary.from],
        ['To', summary.to],
        ['Reply-To', summary.reply_to],
        ['Return-Path', summary.return_path],
        ['Date', summary.date],
        ['Subject', summary.subject],
        ['Message ID', summary.message_id],
        ['Sent by', props.analysis?.provider],
        ['Mailer', summary.mailer],
    ].filter((row) => row[1]);
});
</script>

<template>
    <div class="mt-1">
        <button type="button" :class="[ui.muted, 'inline-flex items-center gap-1 text-xs hover:underline']" :aria-expanded="open" @click="toggle">
            Details <i :class="['bi text-[10px]', open ? 'bi-chevron-up' : 'bi-chevron-down']"></i>
        </button>

        <div v-if="open" :class="[ui.cardDivider, 'mt-2 rounded-lg border px-3 py-2.5 text-xs']">
            <p v-if="loading && !analysis" :class="ui.muted">
                <i class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i> Loading headers…
            </p>
            <p v-else-if="error && !analysis" class="text-rose-600">
                {{ error }}
                <button type="button" class="ml-2 underline" @click="emit('load')">Retry</button>
            </p>
            <template v-else-if="analysis">
                <dl class="grid grid-cols-[minmax(6rem,auto)_1fr] gap-x-4 gap-y-1.5">
                    <template v-for="row in rows" :key="row[0]">
                        <dt :class="[ui.muted, 'font-medium']">{{ row[0] }}</dt>
                        <dd :class="[ui.body, 'min-w-0 break-words']">{{ row[1] }}</dd>
                    </template>
                </dl>

                <button type="button" :class="[ui.strong, 'mt-3 font-semibold']" :aria-expanded="showAllHeaders" @click="showAllHeaders = !showAllHeaders">
                    <i :class="['bi mr-1 text-[10px]', showAllHeaders ? 'bi-chevron-down' : 'bi-chevron-right']"></i>All headers ({{ analysis.headers.length }})
                </button>
                <table v-if="showAllHeaders" class="mt-2 w-full table-fixed">
                    <tbody>
                        <tr v-for="(header, i) in analysis.headers" :key="i" :class="[ui.cardDivider, 'border-t align-top']">
                            <td :class="[ui.muted, 'w-40 break-words py-1 pr-3 font-mono font-medium']">{{ header.name }}</td>
                            <td :class="[ui.body, 'break-words py-1 font-mono']">{{ header.value }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </div>
    </div>
</template>
