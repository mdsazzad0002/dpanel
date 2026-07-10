<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    website: { type: Object, required: true },
    cronJobs: { type: Array, default: () => [] },
});

const page = usePage();
const editingId = ref('');
const deleteForm = useForm({});

const form = useForm({
    name: '',
    expression: '*/5 * * * *',
    command: '',
    status: 'active',
    description: '',
});

const activeCount = computed(() => props.cronJobs.filter((job) => job.status === 'active').length);
const disabledCount = computed(() => props.cronJobs.filter((job) => job.status !== 'active').length);

const submit = () => {
    if (editingId.value) {
        form.patch(route('websites.cronjobs.update', { id: props.website.id, jobId: editingId.value }), { onSuccess: resetForm });
        return;
    }

    form.post(route('websites.cronjobs.store', props.website.id), { onSuccess: resetForm });
};

const editItem = (item) => {
    editingId.value = item.id;
    form.name = item.name ?? '';
    form.expression = item.expression ?? '*/5 * * * *';
    form.command = item.command ?? '';
    form.status = item.status ?? 'active';
    form.description = item.description ?? '';
};

const resetForm = () => {
    editingId.value = '';
    form.reset();
    form.expression = '*/5 * * * *';
    form.status = 'active';
};

const deleteItem = (id) => {
    if (!confirm('Delete this cron job?')) return;
    deleteForm.delete(route('websites.cronjobs.destroy', { id: props.website.id, jobId: id }));
};
</script>

<template>
    <Head :title="`Cron Jobs - ${website.domain}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Cron Job Setup</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage scheduled tasks for {{ website.domain }}.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end gap-2">
                <Link :href="route('websites.manage', website.id)" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to Website Manage
                </Link>
            </div>

            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Total Jobs</p>
                    <p class="mt-2 text-2xl font-semibold">{{ cronJobs.length }}</p>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-white p-4 dark:border-emerald-900 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Active</p>
                    <p class="mt-2 text-2xl font-semibold text-emerald-600">{{ activeCount }}</p>
                </div>
                <div class="rounded-xl border border-amber-200 bg-white p-4 dark:border-amber-900 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Disabled</p>
                    <p class="mt-2 text-2xl font-semibold text-amber-600">{{ disabledCount }}</p>
                </div>
            </section>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Job Name</label>
                    <input v-model="form.name" type="text" placeholder="Cleanup Logs" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Cron Expression</label>
                    <input v-model="form.expression" type="text" placeholder="*/5 * * * *" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p class="mt-1 text-xs text-slate-500">Format: minute hour day month weekday</p>
                    <p v-if="form.errors.expression" class="mt-1 text-xs text-red-600">{{ form.errors.expression }}</p>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Command</label>
                    <input v-model="form.command" type="text" placeholder="/usr/bin/php artisan schedule:run" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.command" class="mt-1 text-xs text-red-600">{{ form.errors.command }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Status</label>
                    <select v-model="form.status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="active">active</option>
                        <option value="disabled">disabled</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Description</label>
                    <input v-model="form.description" type="text" placeholder="Optional notes" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div class="md:col-span-2 flex items-center gap-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ editingId ? 'Update Cron Job' : 'Create Cron Job' }}
                    </button>
                    <button v-if="editingId" type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="resetForm">
                        Cancel
                    </button>
                </div>
            </form>

            <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Expression</th>
                            <th class="px-4 py-3">Command</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="job in cronJobs" :key="job.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ job.name }}</p>
                                <p v-if="job.description" class="text-xs text-slate-500">{{ job.description }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ job.expression }}</td>
                            <td class="px-4 py-3 max-w-md break-all font-mono text-xs">{{ job.command }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-1 text-xs" :class="job.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                    {{ job.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ job.created_at ? new Date(job.created_at).toLocaleString() : '-' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="editItem(job)">
                                        Edit
                                    </button>
                                    <button type="button" class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" @click="deleteItem(job.id)">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="cronJobs.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">No cron jobs added for this website.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

