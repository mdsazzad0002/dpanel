<script setup>
import { ref } from 'vue';

defineProps({
    busy: { type: String, default: '' },
    run: { type: Function, required: true },
});

const blank = () => ({ image: '', name: '', restart: 'unless-stopped', ports: [], env: [], volumes: [] });
const form = ref(blank());

const addPort = () => form.value.ports.push({ host: '', container: '', protocol: 'tcp', public: false });
const addEnv = () => form.value.env.push({ key: '', value: '' });
const addVolume = () => form.value.volumes.push({ source: '', target: '', read_only: false });

const submit = async (run) => {
    const spec = {
        ...form.value,
        image: form.value.image.trim(),
        name: form.value.name.trim(),
        ports: form.value.ports.filter((p) => p.host && p.container).map((p) => ({ ...p, host: Number(p.host), container: Number(p.container) })),
        env: form.value.env.filter((e) => e.key.trim()).map((e) => ({ key: e.key.trim(), value: e.value })),
        volumes: form.value.volumes.filter((v) => v.source.trim() && v.target.trim()).map((v) => ({ ...v, source: v.source.trim(), target: v.target.trim() })),
    };
    if (spec.ports.some((p) => p.public) && !confirm('A public port is open to the internet. Docker writes its own firewall rules, so the server firewall does not block it. Continue?')) return;
    if (await run(spec)) form.value = blank();
};

const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
const small = 'rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800';
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-semibold">Run a container</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            The image is pulled first if it is not on the server. Ports listen on 127.0.0.1 only, unless you make them public.
        </p>

        <form class="mt-4 space-y-4" @submit.prevent="submit(run)">
            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">Image</span>
                    <input v-model="form.image" type="text" required placeholder="nginx:alpine" :class="input" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">Name (optional)</span>
                    <input v-model="form.name" type="text" placeholder="my-app" :class="input" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">Restart</span>
                    <select v-model="form.restart" :class="input">
                        <option value="unless-stopped">Unless stopped</option>
                        <option value="always">Always</option>
                        <option value="on-failure">On failure</option>
                        <option value="no">Never</option>
                    </select>
                </label>
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Ports</span>
                    <button type="button" :class="small" @click="addPort">Add port</button>
                </div>
                <div v-for="(port, i) in form.ports" :key="i" class="mt-2 grid grid-cols-2 items-center gap-2 md:grid-cols-[1fr_1fr_6rem_auto_auto]">
                    <input v-model="port.host" type="number" min="1" max="65535" placeholder="Host port" :class="input" />
                    <input v-model="port.container" type="number" min="1" max="65535" placeholder="Container port" :class="input" />
                    <select v-model="port.protocol" :class="input">
                        <option value="tcp">TCP</option>
                        <option value="udp">UDP</option>
                    </select>
                    <label class="flex items-center gap-1 text-xs"><input v-model="port.public" type="checkbox" /> Public</label>
                    <button type="button" :class="small" @click="form.ports.splice(i, 1)">Remove</button>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Environment variables</span>
                    <button type="button" :class="small" @click="addEnv">Add variable</button>
                </div>
                <div v-for="(env, i) in form.env" :key="i" class="mt-2 grid grid-cols-[1fr_1fr_auto] items-center gap-2">
                    <input v-model="env.key" type="text" placeholder="NAME" :class="input" class="font-mono" />
                    <input v-model="env.value" type="text" placeholder="value" :class="input" />
                    <button type="button" :class="small" @click="form.env.splice(i, 1)">Remove</button>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Volumes</span>
                    <button type="button" :class="small" @click="addVolume">Add volume</button>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    A folder inside a website's home (/home/site-user/docker/my-app) can be edited and uploaded to with that website's File Manager. A plain name makes a named volume that Docker manages.
                </p>
                <div v-for="(volume, i) in form.volumes" :key="i" class="mt-2 grid grid-cols-2 items-center gap-2 md:grid-cols-[1fr_1fr_auto_auto]">
                    <input v-model="volume.source" type="text" placeholder="my-data or /home/site-user/docker/my-app" :class="input" class="font-mono" />
                    <input v-model="volume.target" type="text" placeholder="/path/in/container" :class="input" class="font-mono" />
                    <label class="flex items-center gap-1 text-xs"><input v-model="volume.read_only" type="checkbox" /> Read-only</label>
                    <button type="button" :class="small" @click="form.volumes.splice(i, 1)">Remove</button>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" :disabled="!form.image.trim() || busy === 'run'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">
                    {{ busy === 'run' ? 'Starting…' : 'Run container' }}
                </button>
            </div>
        </form>
    </section>
</template>
