<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import DockerShell from './components/DockerShell.vue';
import ContainerForm from './components/containers/ContainerForm.vue';
import { useDocker } from './composables/useDocker';
import { usedHostPorts } from './composables/useDockerRequest';
import { categories, containerTemplates, instantiate, stackTemplates } from './composables/templates';

const docker = useDocker();
const { status, loading, loadError, busy, message, load, runContainer, panelRoute } = docker;

const category = ref('all');
const kind = ref('all');
const search = ref('');
const form = ref(null);
const deployed = ref(null);

const all = [
    ...containerTemplates.map((t) => ({ ...t, kind: 'container' })),
    ...stackTemplates.map((t) => ({ ...t, kind: 'stack' })),
];

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return all
        .filter((t) => category.value === 'all' || t.category === category.value)
        .filter((t) => kind.value === 'all' || t.kind === kind.value)
        .filter((t) => !needle || `${t.name} ${t.description} ${t.spec?.image || ''}`.toLowerCase().includes(needle));
});

const taken = computed(() => new Set(status.value.containers.map((c) => c.name)));

const deploy = (template) => {
    if (template.kind === 'stack') {
        router.visit(`${panelRoute('docker.stacks')}?template=${encodeURIComponent(template.key)}`);
        return;
    }
    const filled = instantiate(template, usedHostPorts(status.value.containers));
    // Two of the same app need two names.
    let name = filled.spec.name;
    for (let i = 2; taken.value.has(name); i += 1) name = `${filled.spec.name}-${i}`;
    message.value = null;
    deployed.value = null;
    form.value = { initial: { restart: 'unless-stopped', ...filled.spec, name }, note: filled.note };
};

const submit = async (spec) => {
    if (await runContainer(spec)) {
        // Keep the connection details (generated passwords included) on screen.
        deployed.value = { name: spec.name, note: form.value.note };
        form.value = null;
    }
};

onMounted(load);
</script>

<template>
    <DockerShell
        title="App templates"
        description="Ready-made apps: pick one, check the settings, deploy. Passwords are generated for you."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        @refresh="load"
        @dismiss="message = null"
    >
        <section v-if="deployed" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <strong><i class="bi bi-check-circle mr-1"></i>{{ deployed.name }} is running</strong>
                <Link :href="`${panelRoute('docker.containers')}?open=${encodeURIComponent(deployed.name)}`" class="text-xs font-medium underline">Open it →</Link>
            </div>
            <p v-if="deployed.note" class="mt-2 text-xs" v-html="deployed.note"></p>
            <p class="mt-1 text-[11px] opacity-75">Save these details now; passwords are not shown again here (they stay in the container's settings).</p>
        </section>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex flex-wrap gap-1">
                <button v-for="c in categories" :key="c.key" type="button" class="rounded-full px-3 py-1 text-xs font-medium" :class="category === c.key ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'" @click="category = c.key">{{ c.label }}</button>
            </div>
            <select v-model="kind" class="rounded-md border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800" aria-label="Kind">
                <option value="all">Containers and stacks</option>
                <option value="container">Single containers</option>
                <option value="stack">Stacks (several containers)</option>
            </select>
            <input v-model="search" data-docker-search type="search" placeholder="Find an app  ( / )" class="ml-auto w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm sm:w-64 dark:border-slate-700 dark:bg-slate-800" />
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="t in rows" :key="`${t.kind}-${t.key}`" class="flex flex-col rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800"><i :class="[t.icon, t.color]" class="text-lg"></i></span>
                    <div class="min-w-0 flex-1">
                        <h3 class="font-semibold">{{ t.name }}</h3>
                        <div class="mt-0.5 flex min-w-0 flex-wrap gap-1 text-[10px]">
                            <span v-if="t.kind === 'stack'" class="rounded bg-violet-100 px-1.5 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">stack</span>
                            <span v-else class="max-w-full truncate rounded bg-slate-100 px-1.5 font-mono text-slate-600 dark:bg-slate-800 dark:text-slate-300" :title="t.spec.image">{{ t.spec.image }}</span>
                            <span v-if="t.web" class="rounded bg-sky-100 px-1.5 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">web app</span>
                        </div>
                    </div>
                </div>
                <p class="mt-3 flex-1 text-sm text-slate-600 dark:text-slate-300">{{ t.description }}</p>
                <button type="button" :disabled="!status.running" class="mt-4 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40" @click="deploy(t)">
                    {{ t.kind === 'stack' ? 'Set up stack' : 'Set up' }}
                </button>
            </article>
        </div>
        <p v-if="!rows.length" class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">
            No template matches. Anything on Docker Hub still works: <Link :href="`${panelRoute('docker.containers')}?run=`" class="text-indigo-600 hover:underline dark:text-indigo-400">run any image</Link> or <Link :href="`${panelRoute('docker.stacks')}?new=1`" class="text-indigo-600 hover:underline dark:text-indigo-400">paste a compose file</Link>.
        </p>

        <ContainerForm
            :show="!!form"
            mode="run"
            :initial="form?.initial"
            :note="form?.note"
            :networks="status.networks || []"
            :busy="busy === 'run'"
            :error="message?.type === 'error' ? message.text : ''"
            @close="form = null"
            @submit="submit"
        />
    </DockerShell>
</template>
