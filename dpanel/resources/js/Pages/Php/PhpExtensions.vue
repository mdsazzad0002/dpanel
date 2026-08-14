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
    availableExtensions: {
        type: Array,
        default: () => [],
    },
    extensionStates: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const selectedByDefault = props.availableExtensions.filter((extension) => props.extensionStates?.[extension]);

const form = useForm({
    version: props.selectedVersion,
    extensions: selectedByDefault,
});

const switchVersion = () => {
    router.get(
        panelRoute('php.extensions'),
        { version: form.version },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

const submit = () => {
    form.patch(panelRoute('php.extensions.update'));
};
</script>

<template>
    <Head title="PHP Extensions" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">PHP Extensions</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Enable or disable PHP modules for each PHP version.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <form class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
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

                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <label
                        v-for="extension in availableExtensions"
                        :key="extension"
                        class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    >
                        <span>{{ extension }}</span>
                        <input
                            v-model="form.extensions"
                            :value="extension"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                    </label>
                </div>

                <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                    Save PHP Extensions
                </button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
