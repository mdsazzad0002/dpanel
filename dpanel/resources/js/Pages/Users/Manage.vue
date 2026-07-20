<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    users: {
        type: Object,
        default: () => ({
            data: [],
            links: [],
            from: 0,
            to: 0,
            total: 0,
            per_page: 30,
        }),
    },
    activeRoleFilter: {
        type: String,
        default: null,
    },
    roleCounts: {
        type: Object,
        default: () => ({ all: 0, admin: 0, reseller: 0, general: 0 }),
    },
    filters: {
        type: Object,
        default: () => ({ search: '', status: 'all' }),
    },
});

const page = usePage();
const actorRoles = computed(() => page.props.auth?.roles ?? []);
const actorPermissions = computed(() => page.props.auth?.permissions ?? []);
const usersData = computed(() => props.users?.data ?? []);
const canManageUsers = computed(() =>
    actorRoles.value.includes('admin') ||
    actorRoles.value.includes('reseller') ||
    actorPermissions.value.includes('manage_users'),
);
const canOpenAdminPanel = computed(() => actorRoles.value.includes('admin'));
const canOpenResellerPanel = computed(() =>
    actorRoles.value.includes('admin') || actorRoles.value.includes('reseller'),
);
const roleCards = computed(() => ([
    { key: null, label: 'All Users', count: Number(props.roleCounts?.all ?? props.users?.total ?? 0) },
    { key: 'general', label: 'General User', count: Number(props.roleCounts?.general ?? 0) },
    { key: 'admin', label: 'Admin', count: Number(props.roleCounts?.admin ?? 0) },
    { key: 'reseller', label: 'Reseller', count: Number(props.roleCounts?.reseller ?? 0) },
]));
const filterForm = useForm({
    search: String(props.filters?.search ?? ''),
    status: String(props.filters?.status ?? 'all'),
});
const suspendForm = useForm({ suspend: false });
const deleteForm = useForm({});

const currentRouteName = computed(() => {
    if (route().current('admin.panel')) return 'admin.panel';
    if (route().current('reseller.panel')) return 'reseller.panel';
    if (route().current('user.panel')) return 'user.panel';
    return 'users.manage';
});

const currentFilterQuery = computed(() => {
    const query = {};
    const search = filterForm.search.trim();

    if (search !== '') {
        query.search = search;
    }

    if (filterForm.status === 'active' || filterForm.status === 'suspended') {
        query.status = filterForm.status;
    }

    return query;
});

const hasActiveFilters = computed(() => Object.keys(currentFilterQuery.value).length > 0);

const applyFilters = () => {
    const query = { ...currentFilterQuery.value };

    if (props.activeRoleFilter) {
        query.role = props.activeRoleFilter;
    }

    router.get(route(currentRouteName.value), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const resetFilters = () => {
    filterForm.search = '';
    filterForm.status = 'all';
    applyFilters();
};

const roleCardHref = (card) => {
    if (!card?.key) {
        return route('users.manage', currentFilterQuery.value);
    }

    if (card.key === 'admin') {
        return canOpenAdminPanel.value
            ? route('admin.panel', { role: 'admin', ...currentFilterQuery.value })
            : route('users.manage', { role: card.key, ...currentFilterQuery.value });
    }

    if (card.key === 'reseller') {
        return canOpenResellerPanel.value
            ? route('reseller.panel', { role: 'reseller', ...currentFilterQuery.value })
            : route('users.manage', { role: card.key, ...currentFilterQuery.value });
    }

    if (card.key === 'general') {
        return route('user.panel', { role: 'general', ...currentFilterQuery.value });
    }

    return route('users.manage', { role: card.key, ...currentFilterQuery.value });
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const toggleSuspension = (user) => {
    suspendForm.suspend = !user.is_suspended;
    suspendForm.patch(route('users.manage.suspension', user.id), {
        preserveScroll: true,
    });
};

const deleteUser = (user) => {
    if (!confirm(`Delete user "${user.name}"? This cannot be undone.`)) return;

    deleteForm.delete(route('users.manage.destroy', user.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Manage Users" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Manage Users</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Users list with resource limits.</p>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <form class="grid gap-3 md:grid-cols-4" @submit.prevent="applyFilters">
                    <div class="md:col-span-2">
                        <label for="user-search" class="mb-1 block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Search</label>
                        <input
                            id="user-search"
                            v-model="filterForm.search"
                            type="text"
                            placeholder="Name or email"
                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        />
                    </div>
                    <div>
                        <label for="user-status" class="mb-1 block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Status</label>
                        <select
                            id="user-status"
                            v-model="filterForm.status"
                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        >
                            <option value="all">All Status</option>
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button
                            type="submit"
                            class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700"
                        >
                            Apply
                        </button>
                        <button
                            type="button"
                            :disabled="!hasActiveFilters"
                            class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
                            @click="resetFilters"
                        >
                            Reset
                        </button>
                    </div>
                </form>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="card in roleCards"
                    :key="card.label"
                    :href="roleCardHref(card)"
                    preserve-scroll
                    class="rounded-xl border bg-white p-4 dark:bg-slate-900"
                    :class="activeRoleFilter === card.key
                        ? 'border-blue-500 ring-1 ring-blue-200 dark:ring-blue-900/60'
                        : 'border-slate-200 hover:border-slate-300 dark:border-slate-800 dark:hover:border-slate-700'"
                >
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ card.label }}</p>
                    <p class="mt-2 text-2xl font-semibold">{{ card.count }}</p>
                </Link>
            </section>

            <div v-if="canManageUsers" class="flex justify-end">
                <Link :href="route('users.manage.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create User
                </Link>
            </div>

            <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Limits</th>
                            <th class="px-4 py-3">Reseller</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in usersData" :key="user.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ user.name }}</td>
                            <td class="px-4 py-3">{{ user.email }}</td>
                            <td class="px-4 py-3">{{ user.roles.join(', ') || '-' }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-1 text-xs"
                                    :class="user.is_suspended
                                        ? 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300'
                                        : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'"
                                >
                                    {{ user.is_suspended ? 'Suspended' : 'Active' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div>Disk: {{ user.disk_space_mb_limit ?? 'unlimited' }} MB</div>
                                <div>Email: {{ user.mail_accounts_limit ?? 'unlimited' }}</div>
                                <div>DB: {{ user.databases_limit ?? 'unlimited' }}</div>
                                <div>Bandwidth: {{ user.bandwidth_gb_limit ?? 'unlimited' }} GB</div>
                                <div>Websites: {{ user.websites_limit ?? 'unlimited' }}</div>
                            </td>
                            <td class="px-4 py-3">{{ user.reseller?.name ?? '-' }}</td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ formatDate(user.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div v-if="canManageUsers" class="flex items-center gap-2">
                                    <Link
                                        :href="route('users.manage.edit', user.id)"
                                        class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                                    >
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        :disabled="suspendForm.processing"
                                        class="rounded-md border px-2 py-1 text-xs disabled:opacity-50"
                                        :class="user.is_suspended
                                            ? 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300'
                                            : 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300'"
                                        @click="toggleSuspension(user)"
                                    >
                                        {{ user.is_suspended ? 'Unsuspend' : 'Suspend' }}
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="deleteForm.processing"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-300"
                                        @click="deleteUser(user)"
                                    >
                                        Delete
                                    </button>
                                </div>
                                <span v-else class="text-xs text-slate-500 dark:text-slate-400">View only</span>
                            </td>
                        </tr>
                        <tr v-if="usersData.length === 0">
                            <td colspan="8" class="px-4 py-6 text-center text-slate-500">No users found.</td>
                        </tr>
                    </tbody>
                </table>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-800">
                    <p class="text-slate-500 dark:text-slate-400">
                        Showing {{ users.from ?? 0 }}-{{ users.to ?? 0 }} of {{ users.total ?? 0 }} users (30 per page)
                    </p>

                    <div class="flex flex-wrap items-center gap-1">
                        <template v-for="(link, index) in users.links ?? []" :key="`link-${index}-${link.label}-${link.url || 'null'}`">
                            <span
                                v-if="!link.url"
                                class="rounded-md border border-slate-200 px-2 py-1 text-xs text-slate-400 dark:border-slate-700 dark:text-slate-500"
                                v-html="link.label"
                            />
                            <Link
                                v-else
                                :href="link.url"
                                preserve-scroll
                                preserve-state
                                class="rounded-md border px-2 py-1 text-xs"
                                :class="link.active
                                    ? 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-950/30 dark:text-blue-300'
                                    : 'border-slate-200 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800'"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
