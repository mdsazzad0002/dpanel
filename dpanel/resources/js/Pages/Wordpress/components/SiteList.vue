<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    sites: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
});
const selected = defineModel('selected', { type: String, default: '' });
const checked = defineModel('checked', { type: Array, default: () => [] });
const emit = defineEmits(['refresh']);
const { t } = useI18n();

const search = ref('');
const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return needle ? props.sites.filter((site) => site.domain.includes(needle)) : props.sites;
});
const allChecked = computed(() => shown.value.length > 0 && shown.value.every((site) => checked.value.includes(site.id)));
const toggleAll = () => {
    checked.value = allChecked.value ? [] : shown.value.map((site) => site.id);
};
</script>

<template>
    <aside class="rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex items-center gap-2 border-b border-slate-200 p-3 dark:border-slate-800">
            <input :checked="allChecked" type="checkbox" class="rounded" :aria-label="t('wordpress.selectAll')" @change="toggleAll" />
            <input v-model="search" type="search" :placeholder="t('common.search')" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" :aria-label="t('common.refresh')" @click="emit('refresh')">
                <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': loading }"></i>
            </button>
        </div>
        <p v-if="loading && !sites.length" class="p-4 text-sm text-slate-500">{{ t('wordpress.finding') }}</p>
        <ul class="max-h-[70vh] divide-y divide-slate-100 overflow-auto dark:divide-slate-800">
            <li v-for="site in shown" :key="site.id" class="flex items-center gap-2 px-3 py-2" :class="site.id === selected ? 'bg-blue-50 dark:bg-blue-950/40' : ''">
                <input v-model="checked" :value="site.id" type="checkbox" class="rounded" :aria-label="site.domain" />
                <button type="button" class="min-w-0 flex-1 text-left" @click="selected = site.id">
                    <span class="block truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{{ site.domain }}</span>
                    <span class="text-xs text-slate-500">WordPress {{ site.version }} · PHP {{ site.php_version || '—' }}</span>
                </button>
            </li>
        </ul>
    </aside>
</template>
