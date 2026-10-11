<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import NavBar from './MongoAdmin/components/NavBar.vue';
import ServerView from './MongoAdmin/views/ServerView.vue';
import DatabaseView from './MongoAdmin/views/DatabaseView.vue';
import CollectionView from './MongoAdmin/views/CollectionView.vue';
import DocumentView from './MongoAdmin/views/DocumentView.vue';
import { useMongoAdmin } from './MongoAdmin/lib/useMongoAdmin';

// Mongo Admin: MongoDB in the panel, laid out like mongo-express —
// server › database › collection › document, each its own view.
defineProps({
    initialDb: { type: String, default: '' },
    initialCollection: { type: String, default: '' },
});

const {
    panelRoute, databases, version, loading, unreachable, message, busy,
    run, refresh, exportUrl, location, go, onPopState, currentDatabase,
} = useMongoAdmin();

onMounted(() => {
    refresh();
    window.addEventListener('popstate', onPopState);
});
onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));
</script>

<template>
    <Head title="Mongo Admin" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Mongo Admin</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Browse and edit MongoDB databases, collections and documents.</p>
                </div>
                <Link :href="panelRoute('databases.mongodb')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    <i class="bi bi-power"></i> Service &amp; connection
                </Link>
            </div>
        </template>

        <div class="space-y-5">
            <NavBar :location="location" :databases="databases" :version="version" :loading="loading" @go="go" @refresh="refresh" />

            <div v-if="message" class="flex items-start justify-between gap-3 rounded-md border px-4 py-3 text-sm" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'">
                <span>{{ message.text }}</span>
                <button type="button" aria-label="Dismiss" @click="message = null"><i class="bi bi-x-lg"></i></button>
            </div>

            <div v-if="unreachable" class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <p class="font-medium">{{ unreachable }}</p>
                <div class="mt-3 flex gap-2">
                    <Link :href="panelRoute('databases.mongodb')" class="rounded-md bg-amber-600 px-3 py-2 font-medium text-white hover:bg-amber-700">Open MongoDB service</Link>
                    <button type="button" class="rounded-md border border-amber-300 px-3 py-2 hover:bg-amber-100 dark:border-amber-800 dark:hover:bg-amber-900/40" @click="refresh">Try again</button>
                </div>
            </div>

            <p v-else-if="loading && !databases.length" class="py-10 text-center text-sm text-slate-400">Connecting to MongoDB…</p>

            <template v-else>
                <DocumentView
                    v-if="location.doc"
                    :db="location.db"
                    :collection="location.collection"
                    :doc-id="location.doc"
                    :read-only="!!currentDatabase?.system"
                    :run="run"
                    @go="go"
                    @changed="refresh"
                />
                <CollectionView
                    v-else-if="location.collection"
                    :db="location.db"
                    :collection="location.collection"
                    :read-only="!!currentDatabase?.system"
                    :run="run"
                    :export-url="exportUrl"
                    @go="go"
                    @changed="refresh"
                    @notify="message = $event"
                />
                <DatabaseView
                    v-else-if="location.db"
                    :name="location.db"
                    :database="currentDatabase"
                    :run="run"
                    :export-url="exportUrl"
                    :busy="busy"
                    @go="go"
                    @changed="refresh"
                />
                <ServerView v-else :databases="databases" :run="run" :busy="busy" @go="go" @changed="refresh" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
