<script setup>
// "Database target" card on the Import & Clone page: replace one of the
// website's databases, or create a new one. Shared by the upload form, the
// clone form and the share-link import so all three read the same way.
defineProps({
    databases: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    name: { type: String, required: true },
});

const target = defineModel('target', { type: String, default: 'new' });
const newName = defineModel('newName', { type: String, default: '' });
</script>

<template>
    <div class="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60">
        <span class="block text-xs font-semibold text-slate-700 dark:text-slate-200">Database target</span>
        <label v-for="db in databases" :key="db.id" class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm transition-colors dark:border-slate-700 dark:bg-slate-900/50"
            :class="target === db.id ? 'border-indigo-300 dark:border-indigo-700' : ''">
            <input v-model="target" :value="db.id" :name="name" :disabled="disabled" type="radio" class="text-indigo-600 focus:ring-indigo-500" />
            <span v-if="db.missing_on_server" class="text-slate-700 dark:text-slate-200">Create <b>{{ db.database_name }}</b> <span class="block text-xs text-amber-600">Linked to this website but missing on the server — it will be created, nothing is overwritten.</span></span>
            <span v-else class="text-slate-700 dark:text-slate-200">Replace <b>{{ db.database_name }}</b> <span class="block text-xs text-slate-500">Backed up first, then overwritten — you'll be asked to confirm.</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm transition-colors dark:border-slate-700 dark:bg-slate-900/50"
            :class="target === 'new' ? 'border-indigo-300 dark:border-indigo-700' : ''">
            <input v-model="target" value="new" :name="name" :disabled="disabled" type="radio" class="mt-0.5 text-indigo-600 focus:ring-indigo-500" />
            <span class="min-w-0 flex-1">
                <span class="block text-slate-700 dark:text-slate-200">Create new database</span>
                <input v-if="target === 'new'" v-model="newName" :disabled="disabled" type="text" class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white p-1.5 text-xs dark:border-slate-700 dark:bg-slate-800" placeholder="database name" />
            </span>
        </label>
    </div>
</template>
