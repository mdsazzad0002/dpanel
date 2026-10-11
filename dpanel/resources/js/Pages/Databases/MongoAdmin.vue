<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MongoSidebar from './components/mongo/MongoSidebar.vue';
import MongoDatabaseView from './components/mongo/MongoDatabaseView.vue';
import MongoCollectionView from './components/mongo/MongoCollectionView.vue';
import MongoNameModal from './components/mongo/MongoNameModal.vue';
import { formatBytes, useMongoAdmin } from './components/mongo/useMongoAdmin';

// Mongo Admin: browse and edit MongoDB from the panel, like phpMyAdmin.
const props = defineProps({
    initialDb: { type: String, default: '' },
    initialCollection: { type: String, default: '' },
});

const { panelRoute, databases, version, loading, unreachable, message, run, refresh, exportUrl } = useMongoAdmin();

const db = ref(props.initialDb);
const collection = ref(props.initialCollection);
const nameModal = ref({ show: false, mode: 'collection', db: '', current: '', saving: false, error: '' });

const currentDatabase = computed(() => databases.value.find((item) => item.name === db.value) || null);
const currentCollection = computed(() => currentDatabase.value?.collections.find((item) => item.name === collection.value) || null);
const totalSize = computed(() => databases.value.reduce((sum, item) => sum + Number(item.size || 0), 0));

// Keep the selection in the URL so a reload or a shared link opens it again.
const syncUrl = () => {
    const url = new URL(window.location.href);
    url.searchParams.delete('db');
    url.searchParams.delete('collection');
    if (db.value) url.searchParams.set('db', db.value);
    if (db.value && collection.value) url.searchParams.set('collection', collection.value);
    window.history.replaceState(window.history.state, '', url);
};

const select = (database, name = '') => {
    db.value = database;
    collection.value = name;
    message.value = null;
    syncUrl();
};

const reload = async () => {
    await refresh();
    if (db.value && !currentDatabase.value) select('', '');
    else if (collection.value && !currentCollection.value) select(db.value, '');
};

onMounted(reload);

const openModal = (mode, current = '') => {
    nameModal.value = { show: true, mode, db: db.value, current, saving: false, error: '' };
};

const saveModal = async ({ db: database, collection: name }) => {
    nameModal.value.saving = true;
    nameModal.value.error = '';
    try {
        if (nameModal.value.mode === 'rename') {
            await run({ action: 'rename_collection', db: database, collection: nameModal.value.current, to: name }, { notify: true });
        } else {
            await run({ action: 'create_collection', db: database, collection: name }, { notify: true });
        }
        nameModal.value.show = false;
        await refresh();
        select(database, name);
    } catch (e) {
        nameModal.value.error = e.message;
    } finally {
        nameModal.value.saving = false;
    }
};

const dropCollection = async (name) => {
    if (!confirm(`Drop collection ${db.value}.${name}? All its documents and indexes are deleted. This can't be undone.`)) return;
    try {
        await run({ action: 'drop_collection', db: db.value, collection: name }, { notify: true });
        await refresh();
        select(db.value, '');
    } catch (e) {
        message.value = { type: 'error', text: e.message };
    }
};

const dropDatabase = async () => {
    const name = db.value;
    if (prompt(`Drop database ${name} with all its collections? This can't be undone.\n\nType the database name to confirm:`) !== name) return;
    try {
        await run({ action: 'drop_database', db: name }, { notify: true });
        await refresh();
        select('', '');
    } catch (e) {
        message.value = { type: 'error', text: e.message };
    }
};
</script>

<template>
    <Head title="Mongo Admin" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Mongo Admin</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Browse and edit MongoDB<span v-if="version"> {{ version }}</span><span v-if="databases.length"> · {{ databases.length }} databases · {{ formatBytes(totalSize) }}</span>
                    </p>
                </div>
                <Link :href="panelRoute('databases.mongodb')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    <i class="bi bi-power"></i> Service &amp; connection
                </Link>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="message" class="flex items-start justify-between gap-3 rounded-md border px-4 py-3 text-sm" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'">
                <span>{{ message.text }}</span>
                <button type="button" aria-label="Dismiss" @click="message = null"><i class="bi bi-x-lg"></i></button>
            </div>

            <div v-if="unreachable" class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <p class="font-medium">{{ unreachable }}</p>
                <div class="mt-3 flex gap-2">
                    <Link :href="panelRoute('databases.mongodb')" class="rounded-md bg-amber-600 px-3 py-2 font-medium text-white hover:bg-amber-700">Open MongoDB service</Link>
                    <button type="button" class="rounded-md border border-amber-300 px-3 py-2 hover:bg-amber-100 dark:border-amber-800 dark:hover:bg-amber-900/40" @click="reload">Try again</button>
                </div>
            </div>

            <div v-else class="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                <MongoSidebar
                    :databases="databases"
                    :db="db"
                    :collection="collection"
                    :loading="loading"
                    @select="select"
                    @new-database="openModal('database')"
                    @refresh="reload"
                />

                <main class="min-w-0">
                    <MongoCollectionView
                        v-if="currentCollection"
                        :db="db"
                        :collection="currentCollection"
                        :run="run"
                        :export-url="exportUrl"
                        @changed="refresh"
                        @notify="message = $event"
                        @rename="openModal('rename', currentCollection.name)"
                        @drop="dropCollection(currentCollection.name)"
                        @back="select(db, '')"
                    />
                    <MongoDatabaseView
                        v-else-if="currentDatabase"
                        :database="currentDatabase"
                        :run="run"
                        @open="(name) => select(db, name)"
                        @new-collection="openModal('collection')"
                        @rename="(name) => openModal('rename', name)"
                        @drop-collection="dropCollection"
                        @drop-database="dropDatabase"
                    />
                    <section v-else class="rounded-xl border border-slate-200 bg-white p-8 text-center dark:border-slate-800 dark:bg-slate-900">
                        <i class="bi bi-database text-3xl text-amber-500"></i>
                        <p class="mt-2 font-medium">{{ loading ? 'Loading databases…' : 'Pick a database or collection on the left.' }}</p>
                        <button v-if="!loading" type="button" class="mt-4 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="openModal('database')"><i class="bi bi-plus-lg"></i> New database</button>
                    </section>
                </main>
            </div>
        </div>

        <MongoNameModal
            :show="nameModal.show"
            :mode="nameModal.mode"
            :db="nameModal.db"
            :current="nameModal.current"
            :saving="nameModal.saving"
            :error="nameModal.error"
            @save="saveModal"
            @close="nameModal.show = false"
        />
    </AuthenticatedLayout>
</template>
