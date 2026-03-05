<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const form = useForm({
    domain: '',
    root_path: '/home/',
    php_version: '8.3',
    enable_ssl: true,
});

const normalizedDomain = computed(() => form.domain.trim().toLowerCase());
const rootPathManuallyEdited = ref(false);

const rootPathSuffix = computed({
    get: () => form.root_path.replace(/^\/home\//, ''),
    set: (value) => {
        const cleaned = value.replace(/\\/g, '/').replace(/^\/+/, '').trim();
        form.root_path = cleaned ? `/home/${cleaned}` : '/home/';
    },
});

watch(
    normalizedDomain,
    (domain) => {
        if (!rootPathManuallyEdited.value) {
            form.root_path = domain ? `/home/${domain}` : '/home/';
        }
    },
    { immediate: true },
);

const submit = () => {
    if (rootPathSuffix.value.trim() === '') {
        rootPathSuffix.value = normalizedDomain.value;
    }
    form.post(route('websites.store'));
};
</script>

<template>
    <Head title="Create Website" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Create Website</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Use input group: fixed <strong>/home/</strong> prefix and editable suffix.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="route('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    List Website Requests
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Domain</label>
                    <input v-model="form.domain" type="text" placeholder="example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Root Path</label>
                    <div class="flex rounded-md border border-slate-300 dark:border-slate-700">
                        <span class="inline-flex items-center border-r border-slate-300 bg-slate-100 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            /home/
                        </span>
                        <input
                            v-model="rootPathSuffix"
                            @input="rootPathManuallyEdited = true"
                            type="text"
                            placeholder="example.com"
                            class="w-full rounded-r-md border-0 px-3 py-2 text-sm focus:ring-0 dark:bg-slate-800"
                        />
                    </div>
                    <p v-if="form.errors.root_path" class="mt-1 text-xs text-red-600">{{ form.errors.root_path }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">PHP Version</label>
                    <select v-model="form.php_version" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="7.4">7.4</option>
                        <option value="8.0">8.0</option>
                        <option value="8.1">8.1</option>
                        <option value="8.2">8.2</option>
                        <option value="8.3">8.3</option>
                        <option value="8.4">8.4</option>
                    </select>
                    <p v-if="form.errors.php_version" class="mt-1 text-xs text-red-600">{{ form.errors.php_version }}</p>
                </div>
                <div class="flex items-center gap-2 pt-7">
                    <input id="enable_ssl" v-model="form.enable_ssl" type="checkbox" class="rounded border-slate-300" />
                    <label for="enable_ssl" class="text-sm">Enable SSL</label>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Create Website Request
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
