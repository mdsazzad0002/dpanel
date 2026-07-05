<script setup>
import { computed, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    installedVersions: {
        type: Array,
        default: () => [],
    },
    defaultVersion: {
        type: String,
        default: '',
    },
    candidateVersions: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const versionInput = ref('');
const checkingInstalled = ref(false);
const checkError = ref('');
const detectedVersions = ref([]);

const form = useForm({
    installed_versions: props.installedVersions.length ? [...props.installedVersions] : [...props.candidateVersions],
    current_version: props.defaultVersion || (props.installedVersions[0] ?? props.candidateVersions[0] ?? ''),
});
const refreshForm = useForm({});
const detectedSet = computed(() => new Set(detectedVersions.value));

const normalizeVersion = (value) => String(value || '').trim();

const addVersion = () => {
    const version = normalizeVersion(versionInput.value);
    if (!version) return;
    if (version !== 'latest' && !/^\d+\.\d+$/.test(version)) return;
    if (form.installed_versions.includes(version)) {
        versionInput.value = '';
        return;
    }

    form.installed_versions.push(version);
    form.installed_versions.sort((a, b) => {
        if (a === 'latest') return -1;
        if (b === 'latest') return 1;
        return Number(b) - Number(a);
    });
    if (!form.current_version) {
        form.current_version = version;
    }
    versionInput.value = '';
};

const removeVersion = (version) => {
    form.installed_versions = form.installed_versions.filter((item) => item !== version);

    if (!form.installed_versions.length) {
        form.installed_versions = [props.defaultVersion || '8.0'];
    }

    if (!form.installed_versions.includes(form.current_version)) {
        form.current_version = form.installed_versions[0];
    }
};

const submit = () => {
    form.patch(route('php.versions.update'));
};

const loadFromServer = () => {
    refreshForm.post(route('php.versions.refresh'));
};

const isInstalledOnServer = (version) => detectedSet.value.has(version);

const checkInstalledVersions = async () => {
    checkingInstalled.value = true;
    checkError.value = '';

    try {
        const response = await window.axios.get(route('php.versions.check-installed'));
        detectedVersions.value = Array.isArray(response?.data?.installed_versions) ? response.data.installed_versions : [];
    } catch (error) {
        checkError.value = 'Failed to check installed versions.';
        detectedVersions.value = [];
    } finally {
        checkingInstalled.value = false;
    }
};

onMounted(() => {
    checkInstalledVersions();
});
</script>

<template>
    <Head title="PHP Versions" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">PHP Versions</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage installed PHP versions and set the default version.</p>
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
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        :disabled="refreshForm.processing"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800"
                        @click="loadFromServer"
                    >
                        Load From Server
                    </button>
                </div>

                <div class="grid gap-3 md:grid-cols-[1fr_auto]">
                    <input
                        v-model="versionInput"
                        type="text"
                        placeholder="Add version (example: 8.0 or latest)"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        @keydown.enter.prevent="addVersion"
                    />
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        @click="addVersion"
                    >
                        Add Version
                    </button>
                </div>

                <p v-if="form.errors.installed_versions" class="text-xs text-red-600">{{ form.errors.installed_versions }}</p>
                <p v-if="form.errors['installed_versions.0']" class="text-xs text-red-600">{{ form.errors['installed_versions.0'] }}</p>

                <div class="rounded-lg border border-slate-200 dark:border-slate-700">
                    <div class="grid grid-cols-3 gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide dark:border-slate-700 dark:bg-slate-800">
                        <span>Version</span>
                        <span>Status</span>
                        <span class="text-right">Action</span>
                    </div>
                    <div
                        v-for="version in form.installed_versions"
                        :key="version"
                        class="grid grid-cols-3 items-center gap-2 border-t border-slate-200 px-3 py-2 text-sm first:border-t-0 dark:border-slate-700"
                    >
                        <span>PHP {{ version }}</span>
                        <span v-if="version === 'latest'" class="text-xs text-slate-500">Latest Stable alias</span>
                        <span
                            :class="checkingInstalled ? 'bg-slate-100 text-slate-600' : (isInstalledOnServer(version) ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700')"
                            class="inline-flex w-fit rounded-full px-2 py-1 text-xs"
                        >
                            {{ version === 'latest' ? 'Alias' : (checkingInstalled ? 'Checking...' : (isInstalledOnServer(version) ? 'Installed' : 'Not Found')) }}
                        </span>
                        <div class="text-right">
                            <button type="button" class="text-xs text-red-600" @click="removeVersion(version)">Remove</button>
                        </div>
                    </div>
                </div>

                <div class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-700">
                    Server detected: {{ detectedVersions.length ? detectedVersions.join(', ') : 'No versions detected' }}
                </div>
                <div v-if="checkError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                    {{ checkError }}
                </div>

                <div>
                    <label class="mb-1 block text-sm">Default PHP Version</label>
                    <select v-model="form.current_version" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm md:max-w-xs dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="version in form.installed_versions" :key="version" :value="version">
                                {{ version === 'latest' ? 'Latest Stable' : `PHP ${version}` }}
                            </option>
                        </select>
                    <p v-if="form.errors.current_version" class="mt-1 text-xs text-red-600">{{ form.errors.current_version }}</p>
                </div>

                <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                    Save PHP Versions
                </button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
