<script setup>
defineProps({
    filters: { type: Object, required: true },
    sshSupported: { type: Boolean, default: true },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['apply']);

const field = 'rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <form class="flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="emit('apply')">
        <label class="min-w-0 flex-1 basis-56">
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">Search</span>
            <input v-model="filters.search" type="search" placeholder="IP, user, city, browser…" :class="field" class="w-full" />
        </label>
        <label v-if="sshSupported">
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">Source</span>
            <select v-model="filters.channel" :class="field" @change="emit('apply')">
                <option value="">Panel + SSH</option>
                <option value="panel">Panel</option>
                <option value="ssh">SSH</option>
            </select>
        </label>
        <label>
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">Result</span>
            <select v-model="filters.result" :class="field" @change="emit('apply')">
                <option value="">All</option>
                <option value="success">Success</option>
                <option value="failed">Failed</option>
                <option value="logout">Logout</option>
            </select>
        </label>
        <label>
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">From</span>
            <input v-model="filters.from" type="date" :class="field" @change="emit('apply')" />
        </label>
        <label>
            <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">To</span>
            <input v-model="filters.to" type="date" :class="field" @change="emit('apply')" />
        </label>
        <div v-if="filters.country" class="flex items-center gap-1 rounded-md bg-slate-100 px-2 py-2 text-xs dark:bg-slate-800">
            Country: {{ filters.country }}
            <button type="button" class="text-slate-500 hover:text-red-600" title="Remove" @click="filters.country = ''; emit('apply')">
                <i class="bi bi-x-lg" />
            </button>
        </div>
        <button type="submit" :disabled="loading" class="rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-700 disabled:opacity-60 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">
            Search
        </button>
    </form>
</template>
