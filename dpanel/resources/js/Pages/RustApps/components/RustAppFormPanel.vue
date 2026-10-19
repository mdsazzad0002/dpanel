<script setup>
import Offcanvas from '@/Components/Offcanvas.vue';
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    app: { type: Object, default: null },
    owners: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['close']);

const blank = () => ({
    runtime: 'rust',
    name: '',
    site_owner: props.owners[0] || '',
    working_directory: '',
    entry_file: '',
    start_command: '',
    version: '20',
    port: '',
    start_now: true,
});
const form = useForm(blank());
const editing = computed(() => !!props.app);
const home = computed(() => (form.site_owner ? `/home/${form.site_owner}/` : '/home/…/'));

watch(() => props.show, (open) => {
    if (!open) return;
    form.clearErrors();
    // Not form.reset(): a successful submit moves the form's defaults.
    if (!props.app) {
        Object.assign(form, blank());
        return;
    }
    const a = props.app;
    Object.assign(form, {
        name: a.name,
        site_owner: a.site_owner,
        working_directory: a.working_directory.replace(a.home, '').replace(/^\//, ''),
        entry_file: a.entry_file || '',
        start_command: a.start_command || '',
        version: a.version || '',
    });
});

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    if (editing.value) {
        form.put(props.panelRoute('apps.update', { project: props.app.id }), options);
    } else {
        form.post(props.panelRoute('apps.store'), options);
    }
};
</script>

<template>
    <Offcanvas
        :show="show"
        width="lg"
        :title="editing ? `Edit ${app.name}` : 'New Rust app'"
        subtitle="Builds the Cargo project and runs it as the home's Linux user. Publish it from Rules → Port Share."
        @close="emit('close')"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm font-medium">App name</label>
                <input v-model="form.name" type="text" maxlength="120" placeholder="my-api" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <InputError :message="form.errors.name" class="mt-1" />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Home directory</label>
                <select v-model="form.site_owner" :disabled="editing" class="w-full rounded-lg border-slate-300 text-sm disabled:opacity-60 dark:border-slate-600 dark:bg-slate-900">
                    <option v-for="owner in owners" :key="owner" :value="owner">/home/{{ owner }}</option>
                </select>
                <InputError :message="form.errors.site_owner" class="mt-1" />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Working directory</label>
                <div class="flex items-center rounded-lg border border-slate-300 dark:border-slate-600">
                    <span class="whitespace-nowrap pl-3 font-mono text-xs text-slate-500">{{ home }}</span>
                    <input v-model="form.working_directory" type="text" placeholder="apps/my-api" class="w-full rounded-r-lg border-0 font-mono text-sm focus:ring-0 dark:bg-slate-900" />
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">The folder with Cargo.toml. Leave blank to use the home directory.</p>
                <InputError :message="form.errors.working_directory" class="mt-1" />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Arguments <span class="font-normal text-slate-500">(optional)</span></label>
                <input v-model="form.start_command" type="text" placeholder="serve" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Each start runs <code>cargo build --release</code> and starts the Cargo binary with these plain arguments, without a shell. To run another file, start with its path, e.g. <code>./bin/my-api serve</code>. The app reads its port from the <code>PORT</code> environment variable and must listen on 127.0.0.1.</p>
                <InputError :message="form.errors.start_command" class="mt-1" />
            </div>

            <div v-if="!editing" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">Port <span class="font-normal text-slate-500">(optional)</span></label>
                    <input v-model="form.port" type="number" min="1024" max="65535" placeholder="Auto" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <InputError :message="form.errors.port" class="mt-1" />
                </div>
                <label class="flex items-center gap-2 self-end pb-2 text-sm">
                    <input v-model="form.start_now" type="checkbox" class="rounded border-slate-300" />
                    Start after creating
                </label>
            </div>
            <p v-else class="text-xs text-slate-500 dark:text-slate-400">Port {{ app.port }}. A running app restarts with the new settings.</p>

            <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
                <button type="button" @click="emit('close')" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600">Cancel</button>
                <button type="submit" :disabled="form.processing || !form.site_owner" class="rounded-md bg-emerald-600 px-3 py-2 text-sm text-white hover:bg-emerald-700 disabled:opacity-50">
                    <i v-if="form.processing" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ editing ? 'Save' : 'Create app' }}
                </button>
            </div>
        </form>
    </Offcanvas>
</template>
