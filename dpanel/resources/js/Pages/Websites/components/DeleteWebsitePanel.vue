<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
});

const { panelRoute } = usePanelApi();

const deleting = ref(false);
const isAlias = computed(() => ['alias', 'alis'].includes(String(props.website?.type || '').toLowerCase()));

// An Inertia visit, so the controller's flash message shows on whichever page
// it lands on: the websites list on success, this page again on failure.
const deleteWebsite = () => {
    if (deleting.value) return;
    if (!confirm(`Delete ${props.website.domain}?`)) return;

    router.delete(panelRoute('websites.destroy', { id: props.website.id }), {
        preserveScroll: true,
        onStart: () => { deleting.value = true; },
        onFinish: () => { deleting.value = false; },
    });
};
</script>

<template>
    <section class="rounded-2xl border border-red-200 bg-white p-6 shadow-sm dark:border-red-900/60 dark:bg-slate-900/50">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-red-700 dark:text-red-400">Delete website</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    <template v-if="isAlias">Removes the alias {{ website.domain }}. The main site is not affected.</template>
                    <template v-else>Archives {{ website.domain }} to Website Trash, then removes its files, cron jobs and database records. Large sites can take a few minutes.</template>
                </p>
            </div>
            <button
                type="button"
                :disabled="deleting"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 transition hover:border-red-300 hover:bg-red-100 disabled:opacity-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400 dark:hover:border-red-700"
                @click="deleteWebsite"
            >
                <i class="bi" :class="deleting ? 'bi-arrow-repeat animate-spin' : 'bi-trash'"></i>
                {{ deleting ? 'Deleting...' : 'Delete website' }}
            </button>
        </div>
    </section>
</template>
