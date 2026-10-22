<script setup>
import { badge } from '@/Pages/Seo/seoBadges';
import { Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    checks: { type: Array, default: () => [] },
    // check key => { label, href, icon }, shown beside that check.
    actions: { type: Object, default: () => ({}) },
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
                <Link
                    v-if="actions[check.key]"
                    :href="actions[check.key].href"
                    class="flex shrink-0 items-center gap-1 rounded-lg border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700"
                >
                    <i class="bi" :class="actions[check.key].icon"></i>{{ actions[check.key].label }}
                </Link>
            </li>
        </ul>
    </section>
</template>
