<script setup>
import { computed } from 'vue';
import { engineLabel, optionCardClass } from './engines';

// engines: the ones this installer supports, e.g. ['mariadb', 'postgresql'].
// Renders nothing when there is only one to choose from.
const props = defineProps({
    engines: { type: Array, default: () => ['mariadb'] },
    postgresql: { type: Object, default: () => ({ installed: false, active: false }) },
    disabled: { type: Boolean, default: false },
    accent: { type: String, default: 'red' },
});

const model = defineModel({ type: String, default: 'mariadb' });

const unavailable = (engine) => engine === 'postgresql' && !props.postgresql.installed;
const showPostgresqlOff = computed(() => model.value === 'postgresql' && props.postgresql.installed && !props.postgresql.active);
</script>

<template>
    <div v-if="engines.length > 1">
        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Database engine</p>
        <div class="mt-2 flex flex-wrap gap-2">
            <label
                v-for="engine in engines"
                :key="engine"
                class="flex items-center gap-2 rounded-md border px-3 py-1.5"
                :class="[
                    optionCardClass(model === engine, accent),
                    unavailable(engine) ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
                ]"
            >
                <input v-model="model" type="radio" name="database_engine" :value="engine" :disabled="disabled || unavailable(engine)" />
                <span class="text-sm font-medium">{{ engineLabel(engine) }}</span>
            </label>
        </div>
        <p v-if="engines.includes('postgresql') && !postgresql.installed" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
            PostgreSQL is not installed on this server.
        </p>
        <p v-else-if="showPostgresqlOff" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
            PostgreSQL is turned off. Turn it on under Database Management &rarr; PostgreSQL before installing.
        </p>
    </div>
</template>
