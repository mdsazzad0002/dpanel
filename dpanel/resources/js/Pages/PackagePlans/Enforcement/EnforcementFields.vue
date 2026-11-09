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
    <div>
        <label class="mb-1 block text-sm">Emails per hour</label>
        <input v-model.number="form.max_emails_per_hour" type="number" min="0" max="1000000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <p v-if="form.errors.max_emails_per_hour" class="mt-1 text-xs text-red-600">{{ form.errors.max_emails_per_hour }}</p>
    </div>
    <div>
        <label class="mb-1 block text-sm">Emails per day</label>
        <input v-model.number="form.max_emails_per_day" type="number" min="0" max="10000000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <p v-if="form.errors.max_emails_per_day" class="mt-1 text-xs text-red-600">{{ form.errors.max_emails_per_day }}</p>
    </div>
    <div class="grid gap-4 md:col-span-2 md:grid-cols-3">
        <div>
            <label class="mb-1 block text-sm">CPU limit (%)</label>
            <input v-model.number="form.max_cpu_percent" type="number" min="0" max="6400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <p v-if="form.errors.max_cpu_percent" class="mt-1 text-xs text-red-600">{{ form.errors.max_cpu_percent }}</p>
        </div>
        <div>
            <label class="mb-1 block text-sm">Memory limit (MB)</label>
            <input v-model.number="form.max_memory_mb" type="number" min="0" max="1048576" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <p v-if="form.errors.max_memory_mb" class="mt-1 text-xs text-red-600">{{ form.errors.max_memory_mb }}</p>
        </div>
        <div>
            <label class="mb-1 block text-sm">Process limit</label>
            <input v-model.number="form.max_processes" type="number" min="0" max="100000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <p v-if="form.errors.max_processes" class="mt-1 text-xs text-red-600">{{ form.errors.max_processes }}</p>
        </div>
    </div>
    <p class="text-xs text-slate-500 md:col-span-2">
        For the account's websites' PHP, all its sites together. 100% CPU is one full core; 0 means no limit.
        With a limit, the account's PHP runs in its own PHP-FPM, so it cannot slow other accounts down. Needs a Linux server with systemd; applied within five minutes.
    </p>
    <p class="text-xs text-slate-500 md:col-span-2">
        Outgoing mail from the account's mailboxes and websites, each recipient counted once. 0 means no limit.
        Over the limit, mail is held back until the hour or day is over (website mail waits in the queue, mail apps are asked to try later) and the account is notified. Needs the Rspamd module.
    </p>
</template>
