<script setup>
import { computed, ref, watch } from 'vue';
import MongoDocuments from './MongoDocuments.vue';
import MongoIndexes from './MongoIndexes.vue';
import MongoAggregate from './MongoAggregate.vue';

// One collection, in tabs: documents, indexes, aggregation.
const props = defineProps({
    db: { type: String, required: true },
    collection: { type: Object, required: true },
    run: { type: Function, required: true },
    exportUrl: { type: Function, required: true },
});

const emit = defineEmits(['changed', 'notify', 'rename', 'drop', 'back']);

const tab = ref('documents');
watch(() => [props.db, props.collection.name], () => { tab.value = 'documents'; });

// Views have no indexes of their own.
const tabs = computed(() => [
    { key: 'documents', label: 'Documents', icon: 'bi-file-earmark-text' },
    ...(props.collection.type === 'view' ? [] : [{ key: 'indexes', label: 'Indexes', icon: 'bi-lightning' }]),
    { key: 'aggregate', label: 'Aggregation', icon: 'bi-funnel' },
]);
</script>

<template>
    <div class="space-y-4">
        <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs text-slate-500">
                        <button type="button" class="hover:underline" @click="emit('back')">{{ db }}</button> /
                    </p>
                    <h2 class="flex items-center gap-2 truncate text-base font-semibold">
                        <i class="bi" :class="collection.type === 'view' ? 'bi-eye text-violet-500' : 'bi-table text-slate-400'"></i>
                        {{ collection.name }}
                        <span v-if="collection.type === 'view'" class="rounded bg-violet-100 px-1.5 py-0.5 text-[11px] font-normal text-violet-700 dark:bg-violet-950 dark:text-violet-300">read-only view</span>
                    </h2>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('rename')"><i class="bi bi-pencil"></i> Rename</button>
                    <button type="button" class="rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/30" @click="emit('drop')"><i class="bi bi-trash"></i> Drop</button>
                </div>
            </div>
            <nav class="mt-4 flex gap-1 border-b border-slate-200 dark:border-slate-800">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === item.key ? 'border-blue-600 font-medium text-blue-700 dark:text-blue-300' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'"
                    @click="tab = item.key"
                >
                    <i class="bi mr-1" :class="item.icon"></i>{{ item.label }}
                </button>
            </nav>
        </section>

        <MongoDocuments
            v-if="tab === 'documents'"
            :db="db"
            :collection="collection.name"
            :read-only="collection.type === 'view'"
            :run="run"
            :export-url="exportUrl"
            @changed="emit('changed')"
            @notify="emit('notify', $event)"
        />
        <MongoIndexes v-else-if="tab === 'indexes'" :db="db" :collection="collection.name" :read-only="collection.type === 'view'" :run="run" />
        <MongoAggregate v-else :db="db" :collection="collection.name" :run="run" />
    </div>
</template>
