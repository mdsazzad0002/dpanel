<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    until: { type: Number, default: null },
    toggleUrl: { type: String, required: true },
});
const emit = defineEmits(['changed']);

const busy = ref(false);
const message = ref('');
const active = computed(() => Boolean(props.until && props.until * 1000 > Date.now()));
const endsAt = computed(() => (active.value ? new Date(props.until * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''));

const toggle = async () => {
    busy.value = true;
    message.value = '';
    try {
        const { data } = await window.axios.post(props.toggleUrl, { enabled: !active.value }, { headers: { Accept: 'application/json' } });
        message.value = data.message;
        emit('changed', data.settings);
    } catch (error) {
        message.value = error.response?.data?.message || 'Could not change development mode.';
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <section class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border p-5 shadow-sm" :class="active ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-500/10' : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900'">
        <div>
            <h2 class="font-semibold">Development mode <span v-if="active" class="ml-1 rounded-full bg-amber-200 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">on until {{ endsAt }}</span></h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Skip the cache for 3 hours while you edit the site. It turns itself off.</p>
            <p v-if="message" class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ message }}</p>
        </div>
        <button type="button" :disabled="busy" class="rounded-lg border px-4 py-2 text-sm font-semibold disabled:opacity-50" :class="active ? 'border-amber-400 text-amber-800 dark:text-amber-200' : 'border-slate-300 dark:border-slate-700'" @click="toggle">{{ active ? 'Turn off' : 'Turn on' }}</button>
    </section>
</template>
