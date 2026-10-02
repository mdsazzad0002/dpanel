<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    zone: { type: Object, required: true },
    action: { type: String, required: true },
});
const emit = defineEmits(['close']);

const form = useForm({ zone_file: '', file: null, replace: false });

const pickFile = (event) => {
    form.file = event.target.files?.[0] || null;
};

const submit = () => {
    if (form.replace && !confirm(`Replace every record in ${props.zone.domain} (except SOA and NS) with this file?`)) return;
    form.post(props.action, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <div class="fixed inset-0 z-[60] bg-slate-950/40" @click="emit('close')"></div>
    <form class="fixed inset-y-0 right-0 z-[70] grid w-full max-w-xl content-start gap-4 overflow-y-auto bg-white p-6 shadow-2xl dark:bg-slate-900" @submit.prevent="submit">
        <div class="flex items-center justify-between border-b border-slate-200 pb-4 dark:border-slate-700">
            <div>
                <h2 class="text-base font-semibold">Import zone file</h2>
                <p class="text-xs text-slate-500">Into {{ zone.domain }}</p>
            </div>
            <button type="button" title="Close" class="h-9 w-9 rounded-md border border-slate-300 text-lg dark:border-slate-700" @click="emit('close')">×</button>
        </div>

        <p class="text-sm text-slate-600 dark:text-slate-300">
            Upload or paste a BIND zone file. In Cloudflare, open the domain's <strong>DNS → Records → Import and Export → Export</strong>.
            SOA and the old provider's NS records are skipped; every other record is checked before it is saved.
        </p>

        <div>
            <label class="mb-1 block text-sm">Zone file</label>
            <input type="file" accept=".txt,.zone,.db,text/plain" class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 dark:file:bg-slate-800" @change="pickFile" />
            <p v-if="form.errors.file" class="mt-1 text-xs text-red-600">{{ form.errors.file }}</p>
        </div>

        <div>
            <label class="mb-1 block text-sm">…or paste it</label>
            <textarea v-model="form.zone_file" rows="12" spellcheck="false" placeholder="example.com.	300	IN	A	203.0.113.10" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"></textarea>
            <p v-if="form.errors.zone_file" class="mt-1 text-xs text-red-600">{{ form.errors.zone_file }}</p>
        </div>

        <label class="flex items-start gap-2 text-sm">
            <input v-model="form.replace" type="checkbox" class="mt-0.5 rounded border-slate-300" />
            <span>Replace existing records<span class="block text-xs text-slate-500">Off: only add records that are not already in the zone.</span></span>
        </label>

        <div class="flex items-center gap-2">
            <button type="submit" :disabled="form.processing || (!form.file && !form.zone_file.trim())" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                {{ form.processing ? 'Importing…' : 'Import records' }}
            </button>
            <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('close')">Cancel</button>
        </div>
    </form>
</template>
