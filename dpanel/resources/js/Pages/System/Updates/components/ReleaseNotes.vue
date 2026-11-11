<script setup>
import { useI18n } from 'vue-i18n';

// The commits between the running version and the newest release (backlog U6).
defineProps({
    check: { type: Object, default: null },
});
const { t } = useI18n();
const day = (iso) => (iso ? new Date(iso).toLocaleDateString() : '');
</script>

<template>
    <section v-if="check?.update_available" class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-sm font-semibold">{{ t('panelUpdate.changes', { ref: check.latest }) }}</h2>
            <a v-if="check.compare_url" :href="check.compare_url" target="_blank" rel="noopener" class="text-xs font-semibold text-blue-600">{{ t('panelUpdate.onGithub') }}</a>
        </div>
        <p v-if="!check.commits?.length" class="text-xs text-slate-500">{{ t('panelUpdate.noCommitList') }}</p>
        <ul v-else class="max-h-72 divide-y divide-slate-100 overflow-y-auto text-sm dark:divide-slate-800">
            <li v-for="commit in check.commits" :key="commit.sha" class="flex gap-3 py-1.5">
                <a :href="commit.url" target="_blank" rel="noopener" class="font-mono text-xs text-blue-600">{{ commit.sha }}</a>
                <span class="flex-1">{{ commit.message }}</span>
                <span class="whitespace-nowrap text-xs text-slate-500">{{ commit.author }} · {{ day(commit.date) }}</span>
            </li>
        </ul>
    </section>
</template>
