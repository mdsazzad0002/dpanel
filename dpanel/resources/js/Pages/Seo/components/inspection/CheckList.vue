<script setup>
import { badge } from '@/Pages/Seo/seoBadges';

defineProps({
    title: { type: String, required: true },
    checks: { type: Array, default: () => [] },
});
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-700">{{ title }}</h2>
        <ul class="divide-y divide-slate-100 dark:divide-slate-700">
            <li v-for="check in checks" :key="check.key" class="flex items-start gap-3 px-4 py-3">
                <i class="bi mt-0.5" :class="[badge[check.status].icon, badge[check.status].text]"></i>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">{{ check.label }}</p>
                    <p class="break-words text-sm text-slate-500 dark:text-slate-400">{{ check.detail }}</p>
                </div>
            </li>
        </ul>
    </section>
</template>
