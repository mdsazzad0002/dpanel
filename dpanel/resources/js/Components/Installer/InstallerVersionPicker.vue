<script setup>
import { computed } from 'vue';
import { optionCardClass } from './engines';

// versions: [{ value, label, hint?, disabled? }], newest first. The first
// `maxCards` show as radio cards; the rest go in an "Older versions" select so
// long lists (WordPress) stay compact.
const props = defineProps({
    versions: { type: Array, default: () => [] },
    label: { type: String, default: 'Version' },
    name: { type: String, default: 'version' },
    disabled: { type: Boolean, default: false },
    accent: { type: String, default: 'red' },
    maxCards: { type: Number, default: 8 },
});

const model = defineModel({ type: String, default: '' });

const cards = computed(() => props.versions.slice(0, props.maxCards));
const older = computed(() => props.versions.slice(props.maxCards));
const olderSelected = computed(() => older.value.some((version) => version.value === model.value));
</script>

<template>
    <div>
        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ label }}</p>
        <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label
                v-for="version in cards"
                :key="version.value"
                class="flex gap-3 rounded-lg border p-3 transition"
                :class="[
                    optionCardClass(model === version.value, accent),
                    version.disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
                ]"
            >
                <input
                    v-model="model"
                    type="radio"
                    :name="name"
                    class="mt-1"
                    :value="version.value"
                    :disabled="disabled || version.disabled"
                />
                <span>
                    <span class="block text-sm font-semibold">{{ version.label }}</span>
                    <span v-if="version.hint" class="block text-xs text-slate-500 dark:text-slate-400">{{ version.hint }}</span>
                </span>
            </label>
        </div>
        <label v-if="older.length" class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            Older versions:
            <select
                class="rounded-md border px-2 py-1 text-sm dark:bg-slate-800"
                :class="olderSelected ? 'border-slate-500 dark:border-slate-400' : 'border-slate-300 dark:border-slate-700'"
                :value="olderSelected ? model : ''"
                :disabled="disabled"
                @change="model = $event.target.value || model"
            >
                <option value="">Choose…</option>
                <option v-for="version in older" :key="version.value" :value="version.value" :disabled="version.disabled">
                    {{ version.label }}
                </option>
            </select>
        </label>
        <p v-if="versions.length === 0" class="mt-2 text-sm text-slate-500 dark:text-slate-400">No versions available.</p>
    </div>
</template>
