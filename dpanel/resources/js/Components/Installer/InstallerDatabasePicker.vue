<script setup>
import { computed } from 'vue';
import InstallerEnginePicker from './InstallerEnginePicker.vue';
import { engineBadgeClass, engineLabel, optionCardClass } from './engines';

// The database step every installer shares: this website's existing databases
// (with their engine), "create new" (engine choice + preview of what will be
// made) and, for apps that can run without one, "no database".
const props = defineProps({
    databases: { type: Array, default: () => [] },
    newDatabase: { type: Object, default: () => null },
    engines: { type: Array, default: () => ['mariadb'] },
    postgresql: { type: Object, default: () => ({ installed: false, active: false, port: 5432 }) },
    allowExisting: { type: Boolean, default: true },
    allowNone: { type: Boolean, default: false },
    // What happens to an existing database, e.g. "All tables will be dropped".
    existingNote: { type: String, default: '' },
    existingNoteClass: { type: String, default: 'text-slate-500 dark:text-slate-400' },
    // Where the generated password ends up, e.g. ".env".
    passwordFile: { type: String, default: '.env' },
    disabled: { type: Boolean, default: false },
    accent: { type: String, default: 'red' },
});

// "new", "none", or an existing database id.
const selected = defineModel({ type: String, default: 'new' });
// Engine for a new database; an existing one keeps its own.
const engine = defineModel('engine', { type: String, default: 'mariadb' });

const visibleDatabases = computed(() => (props.allowExisting ? props.databases : []));

const preview = computed(() => {
    if (!props.newDatabase) return null;
    return engine.value === 'postgresql'
        ? { ...props.newDatabase, database_host: `127.0.0.1:${props.postgresql.port || 5432}`, charset: 'UTF8', collation: 'default' }
        : props.newDatabase;
});
</script>

<template>
    <div>
        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Database</p>
        <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label
                v-for="db in visibleDatabases"
                :key="db.id"
                class="flex cursor-pointer gap-3 rounded-lg border p-3 transition"
                :class="optionCardClass(selected === db.id, accent)"
            >
                <input v-model="selected" type="radio" name="database" class="mt-1" :value="db.id" :disabled="disabled" />
                <span class="min-w-0">
                    <span class="flex flex-wrap items-center gap-1.5">
                        <span class="break-all text-sm font-semibold">{{ db.database_name }}</span>
                        <span :class="engineBadgeClass(db.engine)" class="rounded-full px-1.5 py-0.5 text-[10px] font-medium">{{ engineLabel(db.engine) }}</span>
                    </span>
                    <span class="block break-all text-xs text-slate-500 dark:text-slate-400">User: {{ db.database_user }}</span>
                    <span v-if="existingNote" class="block text-xs" :class="existingNoteClass">{{ existingNote }}</span>
                </span>
            </label>
            <label class="flex cursor-pointer gap-3 rounded-lg border p-3 transition" :class="optionCardClass(selected === 'new', accent)">
                <input v-model="selected" type="radio" name="database" class="mt-1" value="new" :disabled="disabled" />
                <span>
                    <span class="block text-sm font-semibold">Create new database</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">A fresh database and user for this website</span>
                </span>
            </label>
            <label v-if="allowNone" class="flex cursor-pointer gap-3 rounded-lg border p-3 transition" :class="optionCardClass(selected === 'none', accent)">
                <input v-model="selected" type="radio" name="database" class="mt-1" value="none" :disabled="disabled" />
                <span>
                    <span class="block text-sm font-semibold">No database</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Configure one later</span>
                </span>
            </label>
        </div>

        <div
            v-if="selected === 'new' && preview"
            class="mt-3 space-y-3 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3 text-sm dark:border-slate-700 dark:bg-slate-800/50"
        >
            <InstallerEnginePicker v-model="engine" :engines="engines" :postgresql="postgresql" :disabled="disabled" :accent="accent" />
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Will be created</p>
                <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                    <div class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">Engine:</dt><dd class="font-medium">{{ engineLabel(engine) }}</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">Database:</dt><dd class="break-all font-mono">{{ preview.database_name }}</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">User:</dt><dd class="break-all font-mono">{{ preview.database_user }}</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">Password:</dt><dd>auto-generated, saved in <code>{{ passwordFile }}</code></dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">Host:</dt><dd class="font-mono">{{ preview.database_host }}</dd></div>
                    <div v-if="preview.charset" class="flex gap-2"><dt class="text-slate-500 dark:text-slate-400">Charset:</dt><dd class="font-mono">{{ preview.charset }} / {{ preview.collation }}</dd></div>
                </dl>
            </div>
        </div>
    </div>
</template>
