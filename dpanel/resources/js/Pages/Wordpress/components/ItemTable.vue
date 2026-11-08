<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    title: { type: String, required: true },
    items: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update']);
const { t } = useI18n();

const outdated = computed(() => props.items.filter((item) => item.update));
</script>

<template>
    <div class="rounded-xl border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-2 dark:border-slate-800">
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ title }} <span class="font-normal text-slate-500">({{ items.length }})</span></p>
            <button v-if="outdated.length" type="button" :disabled="disabled" class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-semibold text-white disabled:opacity-50" @click="emit('update', [])">
                <i v-if="busy" class="bi bi-arrow-repeat mr-1 animate-spin"></i>{{ t('wordpress.updateAll', { count: outdated.length }) }}
            </button>
        </div>
        <p v-if="!items.length" class="p-4 text-sm text-slate-500">{{ t('common.none') }}</p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="item in items" :key="item.name">
                        <td class="px-4 py-2">
                            <p class="font-medium text-slate-800 dark:text-slate-100">{{ item.title }}</p>
                            <p class="font-mono text-xs text-slate-500">{{ item.name }}</p>
                        </td>
                        <td class="px-4 py-2 text-xs text-slate-500">{{ item.status }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ item.version }}</td>
                        <td class="px-4 py-2 text-right">
                            <button v-if="item.update" type="button" :disabled="disabled" class="rounded-lg border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 disabled:opacity-50 dark:border-blue-800 dark:text-blue-300" @click="emit('update', [item.name])">
                                {{ t('wordpress.updateTo', { version: item.update }) }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
