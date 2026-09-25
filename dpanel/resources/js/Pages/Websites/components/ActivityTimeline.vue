<script setup>
defineProps({
    activities: {
        type: Array,
        default: () => [],
    },
});

const formatDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    const now = new Date();
    const diffMs = now - parsed;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};
</script>

<template>
    <div class="order-3 min-w-0 space-y-4 xl:col-start-2 xl:row-start-2">
        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                Activity
            </h2>
            <div class="mt-4 space-y-0">
                <div v-if="activities.length === 0" class="flex flex-col items-center py-6 text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800">
                        <svg viewBox="0 0 24 24" class="h-6 w-6 text-slate-400 dark:text-slate-500"
                            fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">No activity yet</p>
                </div>
                <div v-for="(item, index) in activities" :key="item.label"
                    class="relative flex gap-3 pb-4 last:pb-0">
                    <div class="relative flex flex-col items-center">
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400">
                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current">
                                <circle cx="12" cy="12" r="4" />
                            </svg>
                        </div>
                        <div v-if="index < activities.length - 1"
                            class="mt-1 h-full w-px bg-slate-200 dark:bg-slate-700"></div>
                    </div>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                            {{ item.label }}</p>
                        <p class="mt-0.5 truncate text-[13px] font-medium text-slate-700 dark:text-slate-300">
                            {{
                                item.label === 'Request Created' || item.label === 'Request Updated'
                                    ? formatDate(item.value)
                                    : (item.value || '-')
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
