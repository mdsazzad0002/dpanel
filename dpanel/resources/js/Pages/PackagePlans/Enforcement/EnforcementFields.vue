<script setup>
// What happens when an account goes over its package limits (tasks P2, P3).
defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm">Over the disk limit</label>
        <select v-model="form.enforcement_mode" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="report">Report: notify only</option>
            <option value="enforce">Enforce: the server refuses further writes</option>
        </select>
        <p class="mt-1 text-xs text-slate-500">Enforce needs user quotas on the sites filesystem. Disk use is checked every hour.</p>
        <p v-if="form.errors.enforcement_mode" class="mt-1 text-xs text-red-600">{{ form.errors.enforcement_mode }}</p>
    </div>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm">Over the monthly bandwidth limit</label>
        <select v-model="form.over_bandwidth_action" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="notify">Notify only</option>
            <option value="block">Block: websites show a 503 page until the month ends</option>
        </select>
        <p class="mt-1 text-xs text-slate-500">0 GB means no limit. Usage resets on the first day of each month.</p>
        <p v-if="form.errors.over_bandwidth_action" class="mt-1 text-xs text-red-600">{{ form.errors.over_bandwidth_action }}</p>
    </div>
</template>
