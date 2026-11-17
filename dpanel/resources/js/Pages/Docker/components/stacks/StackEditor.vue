<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import CodeArea from '../CodeArea.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// A new stack: a name, its docker-compose file and its variables. The panel
// checks the file with `docker compose config` before saving anything.
const props = defineProps({
    show: { type: Boolean, default: false },
    initial: { type: Object, default: null },
    note: { type: String, default: '' },
    busy: { type: String, default: '' },
    error: { type: String, default: '' },
});

const emit = defineEmits(['close', 'save']);

const starter = `services:
  web:
    image: nginx:alpine
    ports:
      - "127.0.0.1:8090:80"   # 127.0.0.1 keeps it private; reach it through a domain
    restart: unless-stopped
`;

const form = ref({ name: '', compose: '', env: '' });
watch(() => [props.show, props.initial], ([show]) => {
    if (show) form.value = { name: props.initial?.name || '', compose: props.initial?.compose ?? starter, env: props.initial?.env || '' };
}, { immediate: true });

// Compose project names are lowercase; fix what people naturally type.
const tidyName = () => {
    form.value.name = form.value.name.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^[-_]+/, '').slice(0, 63);
};

const save = (deploy) => emit('save', { ...form.value, name: form.value.name.trim(), deploy });
</script>

<template>
    <Modal :show="show" max-width="5xl" @close="$emit('close')">
        <form class="flex max-h-[92vh] flex-col" @submit.prevent="save(true)">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-800">
                <div>
                    <h2 class="text-base font-semibold">{{ t('docker.stackEditor.title') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ t('docker.stackEditor.help') }}</p>
                </div>
                <button type="button" class="rounded-md px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" :aria-label="t('common.close')" @click="$emit('close')"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="flex-1 space-y-4 overflow-y-auto p-5">
                <div v-if="note" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-200" v-html="note"></div>
                <div v-if="error" class="whitespace-pre-line rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</div>
                <label class="block max-w-sm text-sm">
                    <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">{{ t('docker.stackEditor.name') }}</span>
                    <input v-model="form.name" type="text" required placeholder="my-app" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" @blur="tidyName" />
                    <span class="mt-1 block text-[11px] text-slate-500">{{ t('docker.stackEditor.nameHelp') }}</span>
                </label>
                <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <CodeArea v-model="form.compose" label="docker-compose.yml" accept=".yml,.yaml,text/yaml" :rows="22" @save="save(false)" />
                    <div>
                        <CodeArea v-model="form.env" :label="t('docker.stackEditor.variables')" accept=".env,text/plain" :rows="10" placeholder="DB_PASSWORD=change-me" @save="save(false)" />
                        <ul class="mt-3 space-y-1.5 text-xs text-slate-500 dark:text-slate-400">
                            <li><i class="bi bi-check2 mr-1 text-emerald-600"></i><i18n-t keypath="docker.stackEditor.tipVar" scope="global"><template #var><code>${NAME}</code></template></i18n-t></li>
                            <li><i class="bi bi-check2 mr-1 text-emerald-600"></i><i18n-t keypath="docker.stackEditor.tipPorts" scope="global"><template #port><code>"127.0.0.1:8080:80"</code></template></i18n-t></li>
                            <li><i class="bi bi-check2 mr-1 text-emerald-600"></i>{{ t('docker.stackEditor.tipVolumes') }}</li>
                            <li><i class="bi bi-keyboard mr-1"></i>{{ t('docker.stackEditor.tipKeys') }}</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 p-4 dark:border-slate-800">
                <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('close')">{{ t('common.cancel') }}</button>
                <button type="button" :disabled="!form.name || !form.compose.trim() || !!busy" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="save(false)">{{ busy === 'create' ? t('docker.shared.checking') : t('docker.stackEditor.saveOnly') }}</button>
                <button type="submit" :disabled="!form.name || !form.compose.trim() || !!busy" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">
                    <i v-if="busy === 'deploy_new'" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ busy === 'deploy_new' ? t('docker.stackEditor.deploying') : t('docker.stackEditor.saveDeploy') }}
                </button>
            </div>
        </form>
    </Modal>
</template>
