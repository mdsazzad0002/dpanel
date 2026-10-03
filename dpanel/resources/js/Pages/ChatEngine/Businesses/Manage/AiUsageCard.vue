<script setup>
import { computed } from 'vue';

const props = defineProps({
    business: { type: Object, required: true },
});

const limited = computed(() => props.business.ai_reply_limit !== null);
const exhausted = computed(() => limited.value && props.business.ai_replies_used >= props.business.ai_reply_limit);
const percent = computed(() => (limited.value
    ? Math.min(100, (props.business.ai_replies_used / Math.max(props.business.ai_reply_limit, 1)) * 100)
    : 0));
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-700">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                <span class="flex h-6 w-6 items-center justify-center rounded-md bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300"><i class="bi bi-stars text-xs"></i></span>
                AI reply usage
            </h2>
        </div>
        <div class="px-5 py-4">
            <div class="flex items-baseline justify-between">
                <p class="text-2xl font-semibold text-slate-800 dark:text-slate-100">
                    {{ business.ai_replies_used.toLocaleString() }}
                    <span class="text-base font-normal text-slate-400">{{ limited ? `/ ${business.ai_reply_limit.toLocaleString()}` : '· unlimited' }}</span>
                </p>
                <span
                    v-if="limited"
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="exhausted ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300'"
                >{{ exhausted ? 'Exhausted' : 'Active' }}</span>
            </div>

            <div v-if="limited" class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                <div class="h-full rounded-full transition-all" :class="exhausted ? 'bg-red-500' : 'bg-violet-500'" :style="{ width: percent + '%' }"></div>
            </div>

            <p class="mt-3 text-xs text-slate-400">
                Counted from every AI reply this business's apps send — use it to bill the client. The cap comes from the owner's package plan; when reached, auto-reply pauses until the package is upgraded or the owner is switched to a higher plan.
            </p>
            <p v-if="exhausted" class="mt-3 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                <i class="bi bi-exclamation-triangle"></i>
                Reply credit exhausted — auto-reply is currently paused for this business.
            </p>
        </div>
    </section>
</template>
