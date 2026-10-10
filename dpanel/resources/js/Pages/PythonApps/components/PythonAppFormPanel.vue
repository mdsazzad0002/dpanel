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
    runtime: 'python',
    name: '',
    site_owner: props.owners[0] || '',
    working_directory: '',
    entry_file: 'app:app',
    start_command: '',
    version: '3.10',
    python_workers: 4,
    python_mode: 'production',
    python_timeout: 30,
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
        python_workers: a.python_workers || 4,
        python_mode: a.python_mode || 'production',
        python_timeout: a.python_timeout || 30,
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
        :title="editing ? `Edit ${app.name}` : 'New Python app'"
        subtitle="Gets its own .venv and requirements.txt install, runs with gunicorn as the home's Linux user. Publish it from a website's Manage page."
        @close="emit('close')"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm font-medium">App name</label>
                <input v-model="form.name" type="text" maxlength="120" placeholder="my-django" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
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
                    <input v-model="form.working_directory" type="text" placeholder="apps/my-django" class="w-full rounded-r-lg border-0 font-mono text-sm focus:ring-0 dark:bg-slate-900" />
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">The folder with your code and requirements.txt. Empty runs from the home itself.</p>
                <InputError :message="form.errors.working_directory" class="mt-1" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">App (WSGI/ASGI)</label>
                    <input v-model="form.entry_file" type="text" placeholder="app:app" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">e.g. <code>app:app</code> (Flask), <code>mysite.wsgi:application</code> (Django)</p>
                    <InputError :message="form.errors.entry_file" class="mt-1" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Python version</label>
                    <select v-model="form.version" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option v-for="version in versions" :key="version" :value="version">{{ version }}</option>
                    </select>
                    <InputError :message="form.errors.version" class="mt-1" />
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Start command <span class="font-normal text-slate-500">(optional)</span></label>
                <input v-model="form.start_command" type="text" placeholder="uvicorn main:app --host 127.0.0.1 --port $PORT" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Overrides <code>gunicorn {{ form.entry_file || 'app:app' }}</code>. The app must listen on 127.0.0.1 and <code>$PORT</code>.</p>
                <InputError :message="form.errors.start_command" class="mt-1" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium">Workers</label>
                    <input v-model.number="form.python_workers" type="number" min="1" max="32" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <InputError :message="form.errors.python_workers" class="mt-1" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Mode</label>
                    <select v-model="form.python_mode" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="production">Production</option>
                        <option value="development">Development</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Timeout (s)</label>
                    <input v-model.number="form.python_timeout" type="number" min="10" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <InputError :message="form.errors.python_timeout" class="mt-1" />
                </div>
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
                <button type="submit" :disabled="form.processing || !form.site_owner" class="rounded-md bg-sky-600 px-3 py-2 text-sm text-white hover:bg-sky-700 disabled:opacity-50">
                    <i v-if="form.processing" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ editing ? 'Save' : 'Create app' }}
                </button>
            </div>
        </form>
    </Offcanvas>
</template>
