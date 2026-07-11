<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    versions: {
        type: Array,
        default: () => [],
    },
    selectedVersion: {
        type: String,
        default: '',
    },
    configValues: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const form = useForm({
    version: props.selectedVersion,
    memory_limit: props.configValues.memory_limit ?? '256M',
    upload_max_filesize: props.configValues.upload_max_filesize ?? '2G',
    post_max_size: props.configValues.post_max_size ?? '2G',
    max_execution_time: Number(props.configValues.max_execution_time ?? 300),
    max_input_vars: Number(props.configValues.max_input_vars ?? 3000),
    display_errors: props.configValues.display_errors ?? 'Off',
    log_errors: props.configValues.log_errors ?? 'On',
    allow_url_fopen: props.configValues.allow_url_fopen ?? 'On',
});

const switchVersion = () => {
    router.get(
        panelRoute('php.config'),
        { version: form.version },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

const submit = () => {
    form.patch(panelRoute('php.config.update'));
};
</script>

<template>
    <Head title="PHP Config" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">PHP Config</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Configure php.ini style runtime values per PHP version.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">PHP Version</label>
                    <select
                        v-model="form.version"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm md:max-w-xs dark:border-slate-700 dark:bg-slate-800"
                        @change="switchVersion"
                    >
                        <option v-for="version in versions" :key="version" :value="version">
                            PHP {{ version }}
                        </option>
                    </select>
                    <p v-if="form.errors.version" class="mt-1 text-xs text-red-600">{{ form.errors.version }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm">memory_limit</label>
                    <input v-model="form.memory_limit" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.memory_limit" class="mt-1 text-xs text-red-600">{{ form.errors.memory_limit }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">upload_max_filesize</label>
                    <input v-model="form.upload_max_filesize" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.upload_max_filesize" class="mt-1 text-xs text-red-600">{{ form.errors.upload_max_filesize }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">post_max_size</label>
                    <input v-model="form.post_max_size" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.post_max_size" class="mt-1 text-xs text-red-600">{{ form.errors.post_max_size }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">max_execution_time (seconds)</label>
                    <input v-model.number="form.max_execution_time" type="number" min="1" max="3600" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.max_execution_time" class="mt-1 text-xs text-red-600">{{ form.errors.max_execution_time }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">max_input_vars</label>
                    <input v-model.number="form.max_input_vars" type="number" min="100" max="50000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.max_input_vars" class="mt-1 text-xs text-red-600">{{ form.errors.max_input_vars }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">display_errors</label>
                    <select v-model="form.display_errors" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="On">On</option>
                        <option value="Off">Off</option>
                    </select>
                    <p v-if="form.errors.display_errors" class="mt-1 text-xs text-red-600">{{ form.errors.display_errors }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">log_errors</label>
                    <select v-model="form.log_errors" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="On">On</option>
                        <option value="Off">Off</option>
                    </select>
                    <p v-if="form.errors.log_errors" class="mt-1 text-xs text-red-600">{{ form.errors.log_errors }}</p>
                </div>
                <div class="md:col-span-2 md:max-w-md">
                    <label class="mb-1 block text-sm">allow_url_fopen</label>
                    <select v-model="form.allow_url_fopen" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="On">On</option>
                        <option value="Off">Off</option>
                    </select>
                    <p v-if="form.errors.allow_url_fopen" class="mt-1 text-xs text-red-600">{{ form.errors.allow_url_fopen }}</p>
                </div>

                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Save PHP Config
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
