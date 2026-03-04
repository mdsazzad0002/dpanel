<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    websiteRequest: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    domain: props.websiteRequest.domain ?? '',
    root_path: props.websiteRequest.root_path ?? '/var/www',
    php_version: props.websiteRequest.php_version ?? '8.3',
    enable_ssl: !!props.websiteRequest.enable_ssl,
});

const submit = () => {
    form.patch(route('websites.update', props.websiteRequest.id));
};
</script>

<template>
    <Head title="Edit Website Request" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit Website Request</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Update website request and regenerate command.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="route('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to List
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Domain</label>
                    <input v-model="form.domain" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Root Path</label>
                    <input v-model="form.root_path" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
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
                </div>
                <div class="flex items-center gap-2 pt-7">
                    <input id="enable_ssl" v-model="form.enable_ssl" type="checkbox" class="rounded border-slate-300" />
                    <label for="enable_ssl" class="text-sm">Enable SSL</label>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Update Request
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
