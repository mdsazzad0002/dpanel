<script setup>
import { watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    websiteDomains: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    domain: '',
    mailbox: '',
    password: '',
    quota_mb: 1024,
    forwarding_to: '',
});

const submit = () => {
    form.post(route('emails.store'));
};

const generatePassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
    const length = 16;
    const bytes = window.crypto?.getRandomValues
        ? window.crypto.getRandomValues(new Uint32Array(length))
        : Array.from({ length }, () => Math.floor(Math.random() * 100000));

    form.password = Array.from(bytes, (n) => chars[n % chars.length]).join('');
};

watch(
    () => form.domain,
    (domain) => {
        if (!domain || form.mailbox) return;
        const prefix = String(domain).split('.')[0] || 'mail';
        form.mailbox = prefix.replace(/[^a-zA-Z0-9._-]/g, '').slice(0, 20);
    },
);
</script>

<template>
    <Head title="Create Email" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Create Email</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Create a new mailbox account.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="route('emails.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    List Emails
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Website Domain</label>
                    <select v-model="form.domain" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Select domain</option>
                        <option v-for="domain in websiteDomains" :key="domain" :value="domain">{{ domain }}</option>
                    </select>
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Mailbox</label>
                    <input v-model="form.mailbox" type="text" placeholder="support" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.mailbox" class="mt-1 text-xs text-red-600">{{ form.errors.mailbox }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Password</label>
                    <div class="flex gap-2">
                        <input v-model="form.password" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-3 py-2 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                            @click="generatePassword"
                        >
                            Auto Generate
                        </button>
                    </div>
                    <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Quota (MB)</label>
                    <input v-model.number="form.quota_mb" type="number" min="1" max="102400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.quota_mb" class="mt-1 text-xs text-red-600">{{ form.errors.quota_mb }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Forward To (Optional)</label>
                    <input v-model="form.forwarding_to" type="email" placeholder="admin@example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.forwarding_to" class="mt-1 text-xs text-red-600">{{ form.errors.forwarding_to }}</p>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Create Mailbox
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
