<script setup>
// The Docker part of the runtime settings drawer: which image to run, the
// port it listens on, where the site's folder appears inside it, and its
// variables. Edits the parent's object in place through v-model.
const model = defineModel({ type: Object, required: true });

defineProps({
    rootPath: { type: String, default: '' },
});

const presets = [
    { label: 'Nginx (static site)', image: 'nginx:alpine', port: 80, target: '/usr/share/nginx/html' },
    { label: 'Apache httpd (static site)', image: 'httpd:alpine', port: 80, target: '/usr/local/apache2/htdocs' },
];

const applyPreset = (preset) => {
    model.value.image = preset.image;
    model.value.container_port = preset.port;
    model.value.mount_target = preset.target;
};

const addEnv = () => model.value.env.push({ key: '', value: '' });

const input = 'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100';
const label = 'block text-sm font-medium text-slate-700 dark:text-slate-200';
const hint = 'mt-1.5 text-xs text-slate-500 dark:text-slate-400';
</script>

<template>
    <div>
        <span :class="label">Start from</span>
        <div class="mt-1.5 flex flex-wrap gap-2">
            <button v-for="preset in presets" :key="preset.image" type="button"
                class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                @click="applyPreset(preset)">
                {{ preset.label }}
            </button>
        </div>
        <p :class="hint">Or enter any image below. Its Docker Hub page lists the port it listens on and the folder it reads.</p>
    </div>
    <div>
        <label for="docker-image" :class="label">Image</label>
        <input id="docker-image" v-model="model.image" type="text" required placeholder="nginx:alpine" :class="input" class="font-mono" />
        <p :class="hint">Pulled from Docker Hub when it is not on the server yet, e.g. <code>nginx:alpine</code> or <code>ghcr.io/owner/app:1.2</code>.</p>
    </div>
    <div>
        <label for="docker-container-port" :class="label">Port the app listens on</label>
        <input id="docker-container-port" v-model.number="model.container_port" type="number" min="1" max="65535" required placeholder="80" :class="input" />
        <p :class="hint">The port inside the container (often 80, 3000 or 8080). dPanel picks the server-side port and sends this domain to it.</p>
    </div>
    <div>
        <label for="docker-mount-target" :class="label">Site folder inside the container (optional)</label>
        <input id="docker-mount-target" v-model="model.mount_target" type="text" placeholder="/usr/share/nginx/html" :class="input" class="font-mono" />
        <p :class="hint">
            The site's root folder <code>{{ rootPath || '/home/…' }}</code> appears at this path in the container.
            Upload and edit its files with this website's File Manager, then restart the container. Leave blank to mount nothing.
        </p>
    </div>
    <div>
        <div class="flex items-center justify-between">
            <span :class="label">Environment variables</span>
            <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="addEnv">Add variable</button>
        </div>
        <div v-for="(env, i) in model.env" :key="i" class="mt-2 grid grid-cols-[1fr_1fr_auto] items-center gap-2">
            <input v-model="env.key" type="text" placeholder="NAME" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
            <input v-model="env.value" type="text" placeholder="value" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
            <button type="button" class="rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" aria-label="Remove variable" @click="model.env.splice(i, 1)">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <p :class="hint">Passwords and settings the image asks for. Values are stored encrypted.</p>
    </div>
    <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
        <input v-model="model.public" type="checkbox" class="mt-0.5" />
        <span>
            Also open its port to the internet
            <span class="block text-xs text-slate-500 dark:text-slate-400">Off: reachable only through this domain. On: also at http://server-ip:port. Docker opens the port itself, so the server firewall does not block it.</span>
        </span>
    </label>
</template>
