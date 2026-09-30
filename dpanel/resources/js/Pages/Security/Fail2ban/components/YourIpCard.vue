<script setup>
defineProps({
    ip: { type: String, default: '' },
    bannedIn: { type: Array, default: () => [] },
    whitelisted: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

defineEmits(['unban', 'whitelist']);
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Your IP address</p>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="font-mono text-lg">{{ ip || 'unknown' }}</p>
                <p v-if="loading" class="mt-1 text-sm text-slate-500">Checking…</p>
                <p v-else-if="bannedIn.length" class="mt-1 text-sm font-medium text-red-600 dark:text-red-400">
                    Blocked in: {{ bannedIn.join(', ') }}. SSH or mail from this IP will be refused until you unblock it.
                </p>
                <p v-else-if="whitelisted" class="mt-1 text-sm text-emerald-600 dark:text-emerald-400">Whitelisted: fail2ban will never block this IP.</p>
                <p v-else class="mt-1 text-sm text-emerald-600 dark:text-emerald-400">Not blocked.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button v-if="bannedIn.length" type="button" :disabled="busy" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40" @click="$emit('unban', ip)">
                    {{ busy ? 'Unblocking…' : 'Unblock me' }}
                </button>
                <button v-if="ip && !whitelisted" type="button" :disabled="busy || loading" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('whitelist', ip)">
                    Always allow my IP
                </button>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
            Only whitelist a fixed IP you trust. Home and mobile connections often change IP, and a shared network lets others in too.
        </p>
    </section>
</template>
