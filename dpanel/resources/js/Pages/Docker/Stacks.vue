<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import DockerShell from './components/DockerShell.vue';
import StackEditor from './components/stacks/StackEditor.vue';
import StackDetail from './components/stacks/StackDetail.vue';
import { useDocker } from './composables/useDocker';
import { usedHostPorts } from './composables/useDockerRequest';
import { instantiate, stackTemplates } from './composables/templates';

const docker = useDocker();
const { status, loading, loadError, busy, message, act, post, get, panelRoute } = docker;

const stacks = ref([]);
const composeReady = ref(true);
const search = ref('');
const editor = ref(null);
const editorError = ref('');
const detail = ref(null);

const loadStacks = async () => {
    try {
        const data = (await get('docker.stacks.list')).data.data;
        stacks.value = data.stacks || [];
        composeReady.value = data.compose !== false;
    } catch (e) {
        loadError.value = docker.errorText(e);
    }
};

const load = async () => {
    await docker.load();
    if (status.value.running) await loadStacks();
};

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return stacks.value.filter((s) => !needle || s.name.includes(needle));
});

const open = async (name) => {
    try {
        detail.value = (await post('docker.stacks.show', { name })).data.data;
    } catch (e) {
        message.value = { type: 'error', text: docker.errorText(e) };
    }
};

const newStack = (initial = null, note = '') => {
    editorError.value = '';
    editor.value = { initial, note };
};

const create = async ({ name, compose, env, deploy }) => {
    editorError.value = '';
    const action = deploy ? 'deploy_new' : 'create';
    const data = await act(() => post('docker.stacks.action', { action, name, compose, env }), action);
    if (data) {
        editor.value = null;
        detail.value = data;
        loadStacks();
    } else {
        editorError.value = message.value?.text || 'The stack could not be saved.';
    }
};

const save = async ({ name, compose, env, deploy }) => {
    const action = deploy ? 'deploy' : 'save';
    const data = await act(() => post('docker.stacks.action', { action, name, compose, env }), action);
    if (data) {
        detail.value = data;
        loadStacks();
    }
};

const action = async (name, stack, service = '') => {
    const target = service ? `service ${service} of ${stack.name}` : `stack ${stack.name}`;
    let removeVolumes = false;
    if (name === 'stop' && !confirm(`Stop ${target}?`)) return;
    if (name === 'down' && !confirm(`Take ${stack.name} down? Its containers and network are removed; volumes (data) are kept, and Deploy brings it back.`)) return;
    if (name === 'remove') {
        if (!confirm(stack.managed
            ? `Delete stack ${stack.name}? Its containers and its compose file are removed.`
            : `Take ${stack.name} down? Its containers and network are removed.`)) return;
        const typed = window.prompt(`Also delete its volumes, with ALL their data? Type ${stack.name} to delete them too, or leave empty to keep them.`);
        if (typed === null) return;
        removeVolumes = typed.trim() === stack.name;
    }
    const data = await act(() => post('docker.stacks.action', { action: name, name: stack.name, service, remove_volumes: removeVolumes }), name);
    if (data) {
        detail.value = data.removed ? null : data;
        loadStacks();
        docker.load({ quiet: true });
    }
};

const pickTemplate = (key) => {
    const template = stackTemplates.find((t) => t.key === key);
    if (!template) return;
    const filled = instantiate(template, usedHostPorts(status.value.containers));
    const taken = new Set(stacks.value.map((s) => s.name));
    let name = filled.stackName;
    for (let i = 2; taken.has(name); i += 1) name = `${filled.stackName}${i}`;
    newStack({ name, compose: filled.compose, env: filled.env }, filled.note);
};

const stateText = (s) => (s.total ? `${s.running}/${s.total} running` : 'not deployed');
const stateClass = (s) => {
    if (!s.total) return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
    if (s.running === s.total) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300';
    if (s.running) return 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300';
    return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
};

onMounted(async () => {
    await load();
    const params = new URLSearchParams(window.location.search);
    if (params.get('template')) pickTemplate(params.get('template'));
    else if (params.has('new')) newStack();
    if (params.get('open')) open(params.get('open'));
});
</script>

<template>
    <DockerShell
        title="Docker Stacks"
        description="Apps made of several containers, run together from one docker-compose file."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="editor ? null : message"
        :refreshing="busy === 'refresh'"
        :shortcuts="[{ keys: 'n', label: 'New stack' }]"
        :keys="{ n: () => newStack() }"
        @refresh="load"
        @dismiss="message = null"
    >
        <template #actions>
            <button v-if="status.running && composeReady" type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700" title="Shortcut: n" @click="newStack()">
                <i class="bi bi-plus-lg mr-1"></i>New stack
            </button>
        </template>

        <section v-if="!composeReady" class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            <h2 class="font-semibold">Docker Compose is not installed</h2>
            <p class="mt-1">Stacks need the compose plugin. On the server run <code>sudo apt install docker-compose-v2</code> (Ubuntu/Debian) or <code>sudo dnf install docker-compose-plugin</code>, then refresh.</p>
        </section>

        <template v-else>
            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <button type="button" class="flex items-center gap-3 rounded-xl border border-dashed border-indigo-300 bg-white p-4 text-left hover:bg-indigo-50 dark:border-indigo-800 dark:bg-slate-900 dark:hover:bg-indigo-950/40" @click="newStack()">
                    <i class="bi bi-file-earmark-plus text-xl text-indigo-600"></i>
                    <span><span class="block text-sm font-medium">Paste a compose file</span><span class="text-xs text-slate-500">or upload docker-compose.yml</span></span>
                </button>
                <button v-for="t in stackTemplates.slice(0, 3)" :key="t.key" type="button" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left hover:border-indigo-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-700" @click="pickTemplate(t.key)">
                    <i :class="[t.icon, t.color]" class="text-xl"></i>
                    <span class="min-w-0"><span class="block truncate text-sm font-medium">{{ t.name }}</span><span class="text-xs text-slate-500">ready-made stack</span></span>
                </button>
            </section>
            <p class="-mt-3 text-right text-xs"><Link :href="panelRoute('docker.templates')" class="text-indigo-600 hover:underline dark:text-indigo-400">All app templates →</Link></p>

            <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4 dark:border-slate-800">
                    <h2 class="text-base font-semibold">Stacks</h2>
                    <input v-model="search" data-docker-search type="search" placeholder="Find a stack  ( / )" class="ml-auto w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm sm:w-64 dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="s in rows" :key="s.name">
                        <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-left hover:bg-slate-50 dark:hover:bg-slate-800/40" @click="open(s.name)">
                            <i class="bi bi-stack text-violet-600"></i>
                            <span class="min-w-0 flex-1 truncate font-medium">{{ s.name }}</span>
                            <span v-if="!s.managed" class="rounded bg-amber-100 px-1.5 text-[11px] text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">from shell</span>
                            <span :class="stateClass(s)" class="rounded px-2 py-0.5 text-[11px] font-semibold">{{ stateText(s) }}</span>
                            <i class="bi bi-chevron-right text-slate-400"></i>
                        </button>
                    </li>
                    <li v-if="!rows.length" class="px-4 py-10 text-center text-sm text-slate-500">
                        {{ loading ? 'Loading…' : (search ? 'No stack matches.' : 'No stacks yet. Paste a compose file or start from a template above.') }}
                    </li>
                </ul>
            </section>
        </template>

        <StackEditor
            :show="!!editor"
            :initial="editor?.initial"
            :note="editor?.note"
            :busy="['create', 'deploy_new'].includes(busy) ? busy : ''"
            :error="editorError"
            @close="editor = null"
            @save="create"
        />
        <StackDetail :stack="detail" :busy="busy" :message="message" :api="docker" @close="detail = null" @action="action" @save="save" />
    </DockerShell>
</template>
