<script setup>
// The Postfix queue (backlog P5): admins can delete messages or retry them all.
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    queue: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['changed', 'result']);

const page = usePage();
const panelRoute = (name) => {
    const token = page.props.panel?.token;
    return route(name, token ? { token } : {});
};

const selected = ref([]);
const busy = ref(false);
const messages = computed(() => props.queue.messages ?? []);
const allSelected = computed(() => messages.value.length > 0 && selected.value.length === messages.value.length);
watch(messages, (rows) => {
    const ids = new Set(rows.map((row) => row.queue_id));
    selected.value = selected.value.filter((id) => ids.has(id));
});

const toggleAll = () => {
    selected.value = allSelected.value ? [] : messages.value.map((row) => row.queue_id);
};

const formatBytes = (bytes) => {
    const value = Number(bytes || 0);
    if (value >= 1048576) return `${(value / 1048576).toFixed(1)} MB`;
    if (value >= 1024) return `${(value / 1024).toFixed(1)} KB`;
    return `${value} B`;
};

const send = async (request, title) => {
    busy.value = true;
    try {
        const { data } = await request();
        emit('result', { level: 'success', title, message: data?.message ?? '' });
        selected.value = [];
        emit('changed');
    } catch (error) {
        emit('result', { level: 'error', title: 'The queue was not changed', message: error?.response?.data?.message || error?.message || 'Request failed.' });
    } finally {
        busy.value = false;
    }
};

const remove = (ids) => {
    const count = ids.length;
    if (!confirm(`Delete ${count === 1 ? 'this message' : `${count} messages`} from the queue? ${count === 1 ? 'It' : 'They'} will not be delivered, and the sender is not told.`)) return;
    send(() => window.axios.delete(panelRoute('mail-health.queue.destroy'), { data: { ids }, headers: { Accept: 'application/json' } }), 'Deleted from the queue');
};

const retry = () => send(() => window.axios.post(panelRoute('mail-health.queue.retry'), {}, { headers: { Accept: 'application/json' } }), 'Retrying queued mail');
</script>

<template>
    <section class="space-y-3">
        <div v-if="!queue.available" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            <p class="font-semibold">Postfix queue is unavailable</p>
            <p class="mt-1">{{ queue.error }}</p>
        </div>

        <div v-if="canManage && messages.length" class="flex flex-wrap items-center gap-2">
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" :checked="allSelected" class="rounded border-slate-300" @change="toggleAll" />
                Select all
            </label>
            <button type="button" :disabled="busy || selected.length === 0" class="inline-flex items-center gap-1.5 rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40" @click="remove(selected)">
                <i class="bi bi-trash3"></i> Delete selected ({{ selected.length }})
            </button>
            <button type="button" :disabled="busy" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="retry">
                <i class="bi bi-arrow-repeat" :class="{ 'animate-spin': busy }"></i> Retry all now
            </button>
            <span v-if="queue.count > messages.length" class="text-xs text-slate-500">Showing the first {{ messages.length }} of {{ queue.count }}.</span>
        </div>

        <article v-for="message in messages" :key="message.queue_id" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <label class="flex items-center gap-2">
                    <input v-if="canManage" v-model="selected" type="checkbox" :value="message.queue_id" class="rounded border-slate-300" />
                    <span class="font-mono font-semibold">{{ message.queue_id }}</span>
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs capitalize text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">{{ message.status_marker }}</span>
                </label>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-slate-500">{{ formatBytes(message.size_bytes) }}</span>
                    <button v-if="canManage" type="button" :disabled="busy" title="Delete this message" class="text-slate-400 hover:text-red-600 disabled:opacity-50" @click="remove([message.queue_id])">
                        <i class="bi bi-trash3"></i>
                    </button>
                </div>
            </div>
            <p class="mt-2 text-sm"><span class="text-slate-500">From:</span> {{ message.sender || 'MAILER-DAEMON' }}</p>
            <p class="mt-1 text-sm"><span class="text-slate-500">To:</span> {{ message.recipients?.join(', ') || 'Unavailable' }}</p>
        </article>

        <div v-if="queue.available && messages.length === 0" class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700">
            <i class="bi bi-inbox text-3xl text-emerald-500"></i>
            <p class="mt-2 font-medium">Mail queue is empty</p>
        </div>
    </section>
</template>
