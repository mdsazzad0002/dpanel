<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppSidebar from './MongoAdmin/components/AppSidebar.vue';
import AppTopbar from './MongoAdmin/components/AppTopbar.vue';
import ServerView from './MongoAdmin/views/ServerView.vue';
import DatabaseView from './MongoAdmin/views/DatabaseView.vue';
import CollectionView from './MongoAdmin/views/CollectionView.vue';
import DocumentView from './MongoAdmin/views/DocumentView.vue';
import { useMongoAdmin } from './MongoAdmin/lib/useMongoAdmin';
import { useAppChrome } from './MongoAdmin/lib/useAppChrome';

// Mongo Admin: a standalone app outside the panel layout, like dPanel Mail,
// laid out like mongo-express — server › database › collection › document.
// With a `scope` it opens one database signed in as that database's user.
const props = defineProps({
    initialDb: { type: String, default: '' },
    initialCollection: { type: String, default: '' },
    scope: { type: Object, default: null },
});

const {
    panelRoute, databases, version, loading, unreachable, message, busy,
    run, refresh, exportUrl, location, go, onPopState, currentDatabase,
} = useMongoAdmin(props.scope);
const { isDark, toggleTheme, sidebarCollapsed, sidebarOpen, toggleSidebar } = useAppChrome();

onMounted(() => {
    refresh();
    window.addEventListener('popstate', onPopState);
});
onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));
</script>

<template>
    <Head title="Mongo Admin" />

    <div class="flex min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm md:hidden" @click="sidebarOpen = false" />

        <AppSidebar
            :databases="databases"
            :location="location"
            :version="version"
            :collapsed="sidebarCollapsed"
            :mobile-open="sidebarOpen"
            :panel-href="panelRoute('dashboard')"
:service-href="scope ? '' : panelRoute('databases.mongodb')"
            :scoped="!!scope"
            class="md:sticky md:top-0 md:shrink-0"
            @go="go"
            @toggle-collapse="toggleSidebar"
            @close-mobile="sidebarOpen = false"
        />

        <div class="flex min-w-0 flex-1 flex-col">
            <AppTopbar
                :location="location"
                :scoped="!!scope"
                :loading="loading"
                :is-dark="isDark"
                @go="go"
                @refresh="refresh"
                @toggle-theme="toggleTheme"
                @toggle-mobile-sidebar="sidebarOpen = !sidebarOpen"
            />

            <main class="mx-auto w-full max-w-7xl flex-1 space-y-5 p-4 sm:p-6">
                <div v-if="message" class="flex items-start justify-between gap-3 rounded-md border px-4 py-3 text-sm" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'">
                    <span>{{ message.text }}</span>
                    <button type="button" aria-label="Dismiss" @click="message = null"><i class="bi bi-x-lg"></i></button>
                </div>

                <div v-if="unreachable" class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    <p class="font-medium">{{ unreachable }}</p>
                    <div class="mt-3 flex gap-2">
                        <Link v-if="!scope" :href="panelRoute('databases.mongodb')" class="rounded-md bg-amber-600 px-3 py-2 font-medium text-white hover:bg-amber-700">Open MongoDB service</Link>
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
            </main>
        </div>
    </div>
</template>
