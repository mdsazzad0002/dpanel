<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, ref } from 'vue';
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
    assignableRoles: { type: Array, default: () => [] },
    packages: { type: Array, default: () => [] },
});

const page = usePage();
const actorRoles = computed(() => page.props.auth?.roles ?? []);
const actorPermissions = computed(() => page.props.auth?.permissions ?? []);
const actorId = computed(() => Number(page.props.auth?.user?.id ?? page.props.auth?.id ?? 0));
const isCurrentUser = (user) => Number(user?.id) === actorId.value;
const usersData = computed(() => props.users?.data ?? []);
const canManageUsers = computed(() =>
    actorRoles.value.includes('admin') ||
    actorRoles.value.includes('reseller') ||
    actorPermissions.value.includes('manage_users'),
);
const canOpenAdminUsers = computed(() => actorRoles.value.includes('admin'));
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
const userDrawerOpen = ref(false);
const editingUserId = ref(null);
const userForm = useForm({
    name: '', email: '', password: '', password_confirmation: '',
    role: props.assignableRoles.includes('general') ? 'general' : (props.assignableRoles[0] ?? 'general'),
    package_id: '',
});

const resetUserForm = () => {
    userForm.reset();
    userForm.clearErrors();
    userForm.role = props.assignableRoles.includes('general') ? 'general' : (props.assignableRoles[0] ?? 'general');
    editingUserId.value = null;
};
const openCreateUser = () => {
    resetUserForm();
    userDrawerOpen.value = true;
};
const openEditUser = (user) => {
    resetUserForm();
    editingUserId.value = user.id;
    userForm.name = user.name ?? '';
    userForm.email = user.email ?? '';
    userForm.role = user.roles?.[0] ?? 'general';
    userForm.package_id = user.package_id ?? '';
    userDrawerOpen.value = true;
};
const closeUserDrawer = () => {
    userDrawerOpen.value = false;
    resetUserForm();
};
const submitUser = () => {
    const options = { preserveScroll: true, onSuccess: closeUserDrawer };
    if (editingUserId.value) {
        userForm.patch(route('users.manage.update', editingUserId.value), options);
        return;
    }
    userForm.post(route('users.manage.store'), options);
};

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
        return canOpenAdminUsers.value
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
    if (isCurrentUser(user)) return;
    suspendForm.suspend = !user.is_suspended;
    suspendForm.patch(route('users.manage.suspension', user.id), {
        preserveScroll: true,
    });
};

const deleteUser = (user) => {
    if (isCurrentUser(user)) return;
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
                <button type="button" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700" @click="openCreateUser">
                    Create User
                </button>
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
                            <th class="px-4 py-3">Package</th>
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
                            <td class="px-4 py-3">{{ user.package?.name ?? '-' }}</td>
                            <td class="px-4 py-3">{{ user.reseller?.name ?? '-' }}</td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ formatDate(user.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div v-if="canManageUsers" class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                                        @click="openEditUser(user)"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="suspendForm.processing || isCurrentUser(user)"
                                        :title="isCurrentUser(user) ? 'You cannot suspend your own account.' : ''"
                                        class="rounded-md border px-2 py-1 text-xs disabled:opacity-50"
                                        :class="user.is_suspended
                                            ? 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300'
                                            : 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300'"
                                        @click="toggleSuspension(user)"
                                    >
                                        {{ isCurrentUser(user) ? 'Current account' : (user.is_suspended ? 'Unsuspend' : 'Suspend') }}
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="deleteForm.processing || isCurrentUser(user)"
                                        :title="isCurrentUser(user) ? 'You cannot delete your own account.' : ''"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-300"
                                        @click="deleteUser(user)"
                                    >
                                        {{ isCurrentUser(user) ? 'Protected' : 'Delete' }}
                                    </button>
                                </div>
                                <span v-else class="text-xs text-slate-500 dark:text-slate-400">View only</span>
                            </td>
                        </tr>
                        <tr v-if="usersData.length === 0">
                            <td colspan="9" class="px-4 py-6 text-center text-slate-500">No users found.</td>
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

            <Teleport to="body">
                <div v-if="userDrawerOpen" class="fixed inset-0 z-[100]">
                    <button type="button" class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" aria-label="Close user form" @click="closeUserDrawer" />
                    <aside class="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col border-l border-slate-200 bg-white text-slate-900 shadow-2xl transition-colors dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/80 px-6 py-5 dark:border-slate-800 dark:bg-slate-900/80">
                            <div>
                                <h2 class="text-lg font-semibold">{{ editingUserId ? 'Edit User' : 'Create User' }}</h2>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Limits are controlled only by the assigned package.</p>
                            </div>
                            <button type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700" @click="closeUserDrawer">Close</button>
                        </div>
                        <form class="flex-1 space-y-5 overflow-y-auto p-6" @submit.prevent="submitUser">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Name</label>
                                    <input v-model="userForm.name" required class="w-full rounded-md border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:placeholder:text-slate-500" />
                                    <p v-if="userForm.errors.name" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ userForm.errors.name }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Email</label>
                                    <input v-model="userForm.email" type="email" required class="w-full rounded-md border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:placeholder:text-slate-500" />
                                    <p v-if="userForm.errors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ userForm.errors.email }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ editingUserId ? 'New Password (optional)' : 'Password' }}</label>
                                    <input v-model="userForm.password" type="password" :required="!editingUserId" class="w-full rounded-md border-slate-300 bg-white text-slate-900 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                                    <p v-if="userForm.errors.password" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ userForm.errors.password }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Confirm Password</label>
                                    <input v-model="userForm.password_confirmation" type="password" :required="!editingUserId" class="w-full rounded-md border-slate-300 bg-white text-slate-900 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Role</label>
                                    <select v-model="userForm.role" class="w-full rounded-md border-slate-300 bg-white text-slate-900 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                        <option v-for="role in assignableRoles" :key="role" :value="role">{{ role }}</option>
                                    </select>
                                    <p v-if="userForm.errors.role" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ userForm.errors.role }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Resource Package <span v-if="userForm.role === 'general'" class="text-red-500 dark:text-red-400">*</span></label>
                                    <select v-model="userForm.package_id" :required="userForm.role === 'general'" class="w-full rounded-md border-slate-300 bg-white text-slate-900 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                        <option value="">{{ userForm.role === 'general' ? 'Select package' : 'No package' }}</option>
                                        <option v-for="item in packages" :key="item.id" :value="item.id">{{ item.name }}</option>
                                    </select>
                                    <p v-if="userForm.errors.package_id" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ userForm.errors.package_id }}</p>
                                </div>
                            </div>
                            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-700 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-300">
                                Custom disk, email, database, bandwidth and website limits are disabled. The selected package is authoritative.
                            </div>
                            <div class="flex justify-end gap-2 border-t border-slate-200 pt-5 dark:border-slate-800">
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800" @click="closeUserDrawer">Cancel</button>
                                <button type="submit" :disabled="userForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                    {{ userForm.processing ? 'Saving...' : (editingUserId ? 'Update User' : 'Create User') }}
                                </button>
                            </div>
                        </form>
                    </aside>
                </div>
            </Teleport>
        </div>
    </AuthenticatedLayout>
</template>
