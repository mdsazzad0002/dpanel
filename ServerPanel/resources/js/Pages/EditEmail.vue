<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    mailbox: {
        type: Object,
        required: true,
    },
    websiteDomains: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    domain: props.mailbox.domain ?? '',
    mailbox: props.mailbox.mailbox ?? '',
    password: props.mailbox.password ?? '',
    quota_mb: Number(props.mailbox.quota_mb ?? 1024),
    forwarding_to: props.mailbox.forwarding_to ?? '',
});

const domainOptions = computed(() => {
    const current = String(form.domain || '').trim();
    const list = Array.isArray(props.websiteDomains) ? [...props.websiteDomains] : [];

    return current && !list.includes(current) ? [current, ...list] : list;
});

const submit = () => {
    form.patch(route('emails.update', props.mailbox.id));
};
</script>

<template>
    <Head title="Edit Email" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit Email</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Update mailbox account details.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="route('emails.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to List
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Website Domain</label>
                    <select v-model="form.domain" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Select domain</option>
                        <option v-for="domain in domainOptions" :key="domain" :value="domain">{{ domain }}</option>
                    </select>
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Mailbox</label>
                    <input v-model="form.mailbox" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.mailbox" class="mt-1 text-xs text-red-600">{{ form.errors.mailbox }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Password</label>
                    <input v-model="form.password" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Quota (MB)</label>
                    <input v-model.number="form.quota_mb" type="number" min="1" max="102400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.quota_mb" class="mt-1 text-xs text-red-600">{{ form.errors.quota_mb }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Forward To (Optional)</label>
                    <input v-model="form.forwarding_to" type="email" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.forwarding_to" class="mt-1 text-xs text-red-600">{{ form.errors.forwarding_to }}</p>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Update Mailbox
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
