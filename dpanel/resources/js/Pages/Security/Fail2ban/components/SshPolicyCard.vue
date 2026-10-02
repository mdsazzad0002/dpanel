<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    policy: { type: Object, default: null },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['save']);

const maxRetry = ref(3);
watch(() => props.policy, (policy) => {
    maxRetry.value = policy?.max_retry || policy?.default_max_retry || 3;
}, { immediate: true });
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-semibold">SSH login protection</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            An IP that fails to log in to SSH this many times within a day is blocked. Bans from every jail never expire on their own;
            they last until you unblock the IP here. The panel stays reachable, so you can always unblock yourself.
        </p>

        <div v-if="policy" class="mt-3 flex flex-wrap gap-2 text-xs">
            <span class="rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">
                Now: <strong>{{ policy.max_retry ?? '?' }}</strong> failed logins
            </span>
            <span :class="policy.permanent ? 'border-emerald-300 text-emerald-700 dark:border-emerald-800 dark:text-emerald-300' : 'border-amber-300 text-amber-700 dark:border-amber-800 dark:text-amber-300'" class="rounded-full border px-3 py-1">
                {{ policy.permanent ? 'Permanent block' : 'Temporary block (not managed by dPanel yet)' }}
            </span>
            <span class="rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">
                SSH port {{ (policy.ports || []).join(', ') }}
            </span>
        </div>
        <p v-if="policy?.temporary_jails?.length" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            These jails still unban on their own: {{ policy.temporary_jails.join(', ') }}. Click Save to make their bans permanent.
        </p>

        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="emit('save', maxRetry)">
            <label class="text-sm">
                <span class="block text-xs text-slate-500 dark:text-slate-400">Failed logins before block</span>
                <input v-model.number="maxRetry" type="number" min="1" max="10" required class="mt-1 w-28 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <button type="submit" :disabled="busy" class="rounded-md bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                {{ busy ? 'Saving…' : 'Save' }}
            </button>
        </form>
    </section>
</template>
