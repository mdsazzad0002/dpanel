<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: { type: Object, required: true },
});

const { panelRoute, requestJson } = usePanelApi();

const sites = ref([]);
const loading = ref(false);
let searchTimer = null;
let requestSeq = 0;

const options = computed(() => sites.value.map((site) => ({
    value: site.id,
    label: site.type && site.type !== 'primary' ? `${site.domain} (${site.type})` : site.domain,
})));

// Nothing is fetched until the dropdown opens — SearchableSelect emits the
// first `search` on open, then again (debounced here) as the user types.
const search = (query) => {
    window.clearTimeout(searchTimer);
    loading.value = true;
    searchTimer = window.setTimeout(async () => {
        const seq = requestSeq += 1;
        try {
            const url = `${panelRoute('websites.switcher')}?${new URLSearchParams({ q: String(query || '').trim() })}`;
            const data = await requestJson(url, { method: 'GET' });
            if (seq === requestSeq) sites.value = Array.isArray(data?.data) ? data.data : [];
        } catch {
            if (seq === requestSeq) sites.value = [];
        } finally {
            if (seq === requestSeq) loading.value = false;
        }
    }, sites.value.length === 0 ? 0 : 250);
};

const switchTo = (id) => {
    if (!id || String(id) === String(props.website.id)) return;
    router.visit(panelRoute('websites.manage', { id }));
};
</script>

<template>
    <div class="w-full sm:w-72">
        <SearchableSelect
            :model-value="String(website.id)"
            :model-label="website.domain"
            :options="options"
            :loading="loading"
            remote
            placeholder="Switch website"
            search-placeholder="Search websites…"
            @search="search"
            @update:model-value="switchTo"
        />
    </div>
</template>
