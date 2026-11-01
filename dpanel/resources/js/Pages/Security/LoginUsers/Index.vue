<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoginUserPanel from './components/LoginUserPanel.vue';

const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);

const users = ref([]);
const shells = ref([]);
const loading = ref(false);
// Name of the action in flight ('' when idle), so only its button spins.
const pendingAction = ref('');
const error = ref('');
const notice = ref('');
const search = ref('');
const onlyLoginable = ref(true);
const selectedName = ref('');

const selectedUser = computed(() => users.value.find((user) => user.name === selectedName.value) || null);
const filteredUsers = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return users.value
        .filter((user) => !onlyLoginable.value || user.can_login)
        .filter((user) => !needle || [user.name, user.home, user.shell, user.comment].join(' ').toLowerCase().includes(needle));
});
const stats = computed(() => ({
    loginable: users.value.filter((user) => user.can_login).length,
    blocked: users.value.filter((user) => !user.can_login).length,
    online: users.value.filter((user) => user.sessions > 0).length,
}));

const errorMessage = (e, fallback) => {
    const data = e?.response?.data || {};
    if (e?.response?.status === 419) return 'Your session expired — reload the page and try again.';
    return data.message || Object.values(data.errors || {}).flat().join(' ') || e?.message || fallback;
};

const load = async () => {
    loading.value = true;
    try {
        const { data } = await window.axios.get(panelRoute('security.login-users.list'));
        users.value = data.users || [];
        shells.value = data.shells || [];
    } catch (e) { error.value = errorMessage(e, 'Unable to load login users.'); } finally { loading.value = false; }
};

// axios sends the always-fresh XSRF-TOKEN cookie; the <meta> CSRF token goes
// stale after login (Inertia never reloads it), which made every action 419.
const runAction = async (payload) => {
    pendingAction.value = payload.action; error.value = ''; notice.value = '';
    try {
        const { data } = await window.axios.post(panelRoute('security.login-users.control'), payload);
        notice.value = data.message;
        await load();
    } catch (e) { error.value = errorMessage(e, 'Action failed.'); } finally { pendingAction.value = ''; }
};

onMounted(load);
</script>

<template>
    <Head title="Login Users" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Login Users</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Server accounts that can open a shell over SSH — root and regular users (uid ≥ 1000).</p>
            </div>
        </template>

        <div class="space-y-5">
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Can log in</p>
                    <p class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ stats.loginable }}</p>
                </div>
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Blocked</p>
                    <p class="mt-1 text-2xl font-bold text-slate-700 dark:text-slate-300">{{ stats.blocked }}</p>
                </div>
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Online now</p>
                    <p class="mt-1 text-2xl font-bold text-blue-600 dark:text-blue-400">{{ stats.online }}</p>
                </div>
            </div>

            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"><i class="bi bi-exclamation-triangle-fill mr-2"></i>{{ error }}</div>
            <div v-if="notice" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300"><i class="bi bi-check-circle-fill mr-2"></i>{{ notice }}</div>

            <section class="rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center dark:border-slate-800">
                    <input v-model.trim="search" type="text" placeholder="Search user, home, shell…" class="flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input v-model="onlyLoginable" type="checkbox" class="rounded" />
                        Only users who can log in
                    </label>
                    <button type="button" :disabled="loading" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300" @click="load">
                        <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': loading }"></i> Refresh
                    </button>
                </div>

                <div v-if="loading && users.length" class="h-0.5 w-full overflow-hidden bg-blue-100 dark:bg-blue-950"><div class="h-full w-1/3 animate-pulse bg-blue-500"></div></div>
                <ul v-if="loading && !users.length" class="divide-y divide-slate-100 dark:divide-slate-800" aria-label="Loading users">
                    <li v-for="n in 4" :key="n" class="flex animate-pulse items-center gap-3 p-4">
                        <div class="h-10 w-10 rounded-xl bg-slate-200 dark:bg-slate-800"></div>
                        <div class="flex-1 space-y-2"><div class="h-3 w-40 rounded bg-slate-200 dark:bg-slate-800"></div><div class="h-2.5 w-64 rounded bg-slate-100 dark:bg-slate-800/60"></div></div>
                    </li>
                </ul>
                <div v-else-if="!filteredUsers.length" class="py-12 text-center text-sm text-slate-500">No users match.</div>
                <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="user in filteredUsers" :key="user.name" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm font-bold text-white" :class="user.uid === 0 ? 'bg-gradient-to-br from-red-500 to-orange-500' : 'bg-gradient-to-br from-violet-500 to-indigo-500'">
                                {{ user.name.charAt(0).toUpperCase() }}
                            </div>
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 font-semibold text-slate-900 dark:text-slate-100">
                                    {{ user.name }}
                                    <span v-if="user.is_admin" class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300">sudo</span>
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-medium" :class="user.can_login ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'">{{ user.can_login ? 'Can log in' : 'Blocked' }}</span>
                                    <span v-if="user.password_locked" class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-medium text-red-700 dark:bg-red-950 dark:text-red-300">password locked</span>
                                    <span v-if="user.sessions" class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-950 dark:text-blue-300">{{ user.sessions }} online</span>
                                </p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">uid {{ user.uid }} · {{ user.home }} · {{ user.shell }} · {{ user.ssh_keys }} SSH key(s)</p>
                            </div>
                        </div>
                        <button type="button" class="shrink-0 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800" @click="selectedName = user.name; notice = ''; error = ''">
                            <i class="bi bi-sliders mr-1"></i>Manage
                        </button>
                    </li>
                </ul>
            </section>
        </div>

        <LoginUserPanel :user="selectedUser" :shells="shells" :busy="pendingAction !== ''" :pending="pendingAction" :error="error" :notice="notice" @close="selectedName = ''; notice = ''" @action="runAction" />
    </AuthenticatedLayout>
</template>
