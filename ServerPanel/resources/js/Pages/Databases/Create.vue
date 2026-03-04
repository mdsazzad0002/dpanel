<script setup>
import { ref, watch } from 'vue';
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
    database_name: '',
    database_user: '',
    database_password: '',
    database_host: 'localhost',
    charset: 'utf8mb4',
    collation: 'utf8mb4_unicode_ci',
});
const showPassword = ref(false);

const submit = () => {
    form.post(route('databases.store'));
};

const generatePassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
    const bytes = new Uint32Array(16);
    window.crypto.getRandomValues(bytes);
    form.database_password = Array.from(bytes, (value) => chars[value % chars.length]).join('');
    showPassword.value = true;
};

const sanitizePrefix = (domain) => {
    const root = String(domain).trim().toLowerCase().split('.')[0] || '';
    return root.replace(/[^a-z0-9_]/g, '_').slice(0, 12);
};

watch(
    () => form.domain,
    (domain) => {
        const prefix = sanitizePrefix(domain);
        if (!prefix) return;

        if (!form.database_name) {
            form.database_name = `${prefix}_db`;
        }
        if (!form.database_user) {
            form.database_user = `${prefix}_usr`;
        }
    },
);
</script>

<template>
    <Head title="Create Database" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Create Database</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Create a new MySQL/MariaDB database request.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="route('databases.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    List Databases
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Website Domain</label>
                    <select v-model="form.domain" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Select domain</option>
                        <option v-for="domain in websiteDomains" :key="domain" :value="domain">
                            {{ domain }}
                        </option>
                    </select>
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                    <p v-if="websiteDomains.length === 0" class="mt-1 text-xs text-amber-600">
                        No website domains found. Create website first, or type domain manually below.
                    </p>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Domain (Manual Input)</label>
                    <input v-model="form.domain" type="text" placeholder="example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Database Name</label>
                    <input v-model="form.database_name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.database_name" class="mt-1 text-xs text-red-600">{{ form.errors.database_name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Database User</label>
                    <input v-model="form.database_user" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.database_user" class="mt-1 text-xs text-red-600">{{ form.errors.database_user }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Database Password</label>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="form.database_password"
                            :type="showPassword ? 'text' : 'password'"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        />
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                            @click="showPassword = !showPassword"
                        >
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-blue-300 px-3 py-2 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-400 dark:hover:bg-blue-900/20"
                            @click="generatePassword"
                        >
                            Generate
                        </button>
                    </div>
                    <p v-if="form.errors.database_password" class="mt-1 text-xs text-red-600">{{ form.errors.database_password }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Host</label>
                    <input v-model="form.database_host" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.database_host" class="mt-1 text-xs text-red-600">{{ form.errors.database_host }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Charset</label>
                    <select v-model="form.charset" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="utf8mb4">utf8mb4</option>
                        <option value="utf8">utf8</option>
                        <option value="latin1">latin1</option>
                    </select>
                    <p v-if="form.errors.charset" class="mt-1 text-xs text-red-600">{{ form.errors.charset }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Collation</label>
                    <select v-model="form.collation" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>
                        <option value="utf8mb4_general_ci">utf8mb4_general_ci</option>
                        <option value="utf8_general_ci">utf8_general_ci</option>
                        <option value="latin1_swedish_ci">latin1_swedish_ci</option>
                    </select>
                    <p v-if="form.errors.collation" class="mt-1 text-xs text-red-600">{{ form.errors.collation }}</p>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Save Database Request
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
