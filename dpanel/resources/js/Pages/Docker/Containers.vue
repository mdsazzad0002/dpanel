<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import DockerShell from './components/DockerShell.vue';
import DockerUsageGuide from './components/DockerUsageGuide.vue';
import ContainerList from './components/containers/ContainerList.vue';
import ContainerForm from './components/containers/ContainerForm.vue';
import ContainerDetails from './components/containers/ContainerDetails.vue';
import { useDocker } from './composables/useDocker';
import { freePort, usedHostPorts } from './composables/useDockerRequest';

const docker = useDocker();
const { status, loading, loadError, busy, message, load, containerAction, bulkAction, renameContainer, runContainer, recreateContainer, pruneContainers, panelRoute } = docker;

// The form: { mode, initial } or null when closed.
const form = ref(null);
// The details dialog: which container, on which tab.
const openId = ref(null);
const openTab = ref('overview');
const openContainer = computed(() => status.value.containers.find((c) => c.id === openId.value || c.name === openId.value) || null);

const newContainer = (initial = null) => {
    message.value = null;
    form.value = { mode: 'run', initial };
};
const open = (container, tab = 'overview') => {
    openTab.value = tab;
    openId.value = container.id;
};

const submit = async (spec) => {
    const ok = form.value.mode === 'recreate'
        ? await recreateContainer(form.value.id, spec)
        : await runContainer(spec);
    if (ok) {
        form.value = null;
        if (openId.value) openId.value = null;
    }
};

const action = async (name, container) => {
    const result = await containerAction(name, container);
    if (result && name === 'remove') openId.value = null;
    // A recreate gives the container a new ID; keep its details open by name.
    if (result && name === 'update') openId.value = container.name;
};

const rename = async (container) => {
    const name = window.prompt(`New name for ${container.name}`, container.name)?.trim();
    if (!name || name === container.name) return;
    if (await renameContainer(container, name)) openId.value = name;
};

// A copy cannot publish the same server ports, so each moves to the next free one.
const duplicate = (spec) => {
    const used = usedHostPorts(status.value.containers);
    form.value = {
        mode: 'duplicate',
        initial: { ...spec, name: spec.name ? `${spec.name}-copy` : '', ports: spec.ports.map((p) => ({ ...p, host: freePort(p.host, used) })) },
    };
};

const stopped = computed(() => status.value.containers.filter((c) => c.state === 'exited' || c.state === 'created').length);

onMounted(async () => {
    await load();
    // Shortcuts from other pages: ?run=image opens the form, ?open=name the details.
    const params = new URLSearchParams(window.location.search);
    if (params.has('run')) newContainer(params.get('run') ? { image: params.get('run') } : null);
    if (params.get('open')) openId.value = params.get('open');
});
</script>

<template>
    <DockerShell
        title="Docker Containers"
        description="Run, inspect and control the containers on this server."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        :shortcuts="[{ keys: 'n', label: 'Run a new container' }]"
        :keys="{ n: () => newContainer() }"
        @refresh="load"
        @dismiss="message = null"
    >
        <template #actions>
            <button v-if="status.running" type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700" title="Shortcut: n" @click="newContainer()">
                <i class="bi bi-plus-lg mr-1"></i>Run container
            </button>
        </template>

        <div v-if="status.running" class="grid gap-3 sm:grid-cols-3">
            <button type="button" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left hover:border-indigo-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-700" @click="newContainer()">
                <i class="bi bi-plus-square text-xl text-indigo-600"></i>
                <span><span class="block text-sm font-medium">Run a container</span><span class="text-xs text-slate-500">Any image, or paste a docker run command</span></span>
            </button>
            <Link :href="panelRoute('docker.templates')" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 hover:border-indigo-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-700">
                <i class="bi bi-grid-3x3-gap text-xl text-emerald-600"></i>
                <span><span class="block text-sm font-medium">One-click apps</span><span class="text-xs text-slate-500">Redis, Postgres, Elasticsearch, WordPress…</span></span>
            </Link>
            <button type="button" :disabled="!stopped || busy === 'prune-containers'" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left hover:border-indigo-300 disabled:opacity-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-700" @click="pruneContainers">
                <i class="bi bi-trash3 text-xl text-slate-500"></i>
                <span><span class="block text-sm font-medium">Remove stopped containers</span><span class="text-xs text-slate-500">{{ stopped ? `${stopped} stopped; volumes are kept` : 'None stopped' }}</span></span>
            </button>
        </div>

        <ContainerList
            :containers="status.containers"
            :loading="loading"
            :busy="busy"
            :stats="docker.stats"
            @action="action"
            @bulk="bulkAction"
            @open="open"
        >
            <template #empty>
                <button type="button" class="ml-1 text-indigo-600 hover:underline dark:text-indigo-400" @click="newContainer()">Run one</button>
                or pick a ready-made app from
                <Link :href="panelRoute('docker.templates')" class="text-indigo-600 hover:underline dark:text-indigo-400">App templates</Link>.
            </template>
        </ContainerList>

        <DockerUsageGuide v-if="status.running" />

        <ContainerForm
            :show="!!form"
            :mode="form?.mode || 'run'"
            :initial="form?.initial"
            :networks="status.networks || []"
            :busy="busy === 'run'"
            :error="message?.type === 'error' ? message.text : ''"
            @close="form = null"
            @submit="submit"
        />

        <ContainerDetails
            :container="openContainer"
            :tab="openTab"
            :networks="status.networks || []"
            :busy="busy"
            :message="message"
            :docker="docker"
            @close="openId = null"
            @action="action"
            @edit="(spec, c) => { message = null; form = { mode: 'recreate', id: c.name, initial: spec } }"
            @duplicate="duplicate"
            @rename="rename"
            @changed="load({ quiet: true })"
        />
    </DockerShell>
</template>
