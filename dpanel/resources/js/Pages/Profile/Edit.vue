<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import TwoFactorForm from './Partials/TwoFactorForm.vue';

const props = defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});
const roles = computed(() => page.props.auth?.roles ?? []);
const twoFactor = computed(() => page.props.twoFactor ?? {});
const emailVerified = computed(() => Boolean(user.value?.email_verified_at));
const twoFactorEnabled = computed(() => Boolean(twoFactor.value.enabled ?? false));
const twoFactorMethod = computed(() => String(twoFactor.value.method ?? '').replace(/_/g, ' ') || 'not set');
const userInitials = computed(() => String(user.value?.name ?? 'User')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || 'U');

const tabs = [
    {
        key: 'profile',
        label: 'Profile',
        hint: 'Name and email',
        icon: 'bi bi-person-vcard',
    },
    {
        key: 'password',
        label: 'Password',
        hint: 'Change password',
        icon: 'bi bi-shield-lock',
    },
    {
        key: 'two-factor',
        label: '2FA',
        hint: 'Login verification',
        icon: 'bi bi-phone-lock',
    },
    {
        key: 'danger',
        label: 'Danger Zone',
        hint: 'Delete account',
        icon: 'bi bi-exclamation-triangle',
    },
];

const activeTab = ref('profile');
const setActiveTab = (tab) => {
    activeTab.value = tab;
};
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 dark:text-slate-400">
                    Account Center
                </span>
                <h2 class="text-xl font-semibold leading-tight text-slate-900 dark:text-slate-100">
                    Profile & Security
                </h2>
            </div>
        </template>

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl space-y-6">
                <section class="overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-[0_20px_60px_-30px_rgba(15,23,42,0.45)] dark:border-slate-800 dark:bg-slate-900">
                    <div class="grid gap-6 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 px-6 py-8 text-white md:grid-cols-[1.4fr_0.9fr] md:px-8">
                        <div class="space-y-4">
                            <div class="flex items-center gap-4">
                                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-lg font-bold ring-1 ring-white/15">
                                    {{ userInitials }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-[0.3em] text-white/60">
                                        Signed in as
                                    </p>
                                    <h1 class="truncate text-2xl font-semibold md:text-3xl">
                                        {{ user.name || 'User' }}
                                    </h1>
                                    <p class="truncate text-sm text-white/70">
                                        {{ user.email || 'No email available' }}
                                    </p>
                                </div>
                            </div>

                            <p class="max-w-2xl text-sm leading-6 text-white/75">
                                Manage your profile information, password, and login verification from one place.
                            </p>

                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-medium text-white/90">
                                    {{ roles.join(', ') || 'No role assigned' }}
                                </span>
                                <span
                                    class="rounded-full px-3 py-1 text-xs font-medium"
                                    :class="emailVerified ? 'bg-emerald-500/15 text-emerald-200 ring-1 ring-emerald-400/20' : 'bg-amber-500/15 text-amber-200 ring-1 ring-amber-400/20'"
                                >
                                    {{ emailVerified ? 'Email verified' : 'Email unverified' }}
                                </span>
                                <span
                                    class="rounded-full px-3 py-1 text-xs font-medium"
                                    :class="twoFactorEnabled ? 'bg-cyan-500/15 text-cyan-200 ring-1 ring-cyan-400/20' : 'bg-white/10 text-white/70 ring-1 ring-white/15'"
                                >
                                    {{ twoFactorEnabled ? `2FA enabled · ${twoFactorMethod}` : '2FA disabled' }}
                                </span>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-1">
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur">
                                <p class="text-xs uppercase tracking-[0.25em] text-white/50">Profile</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ user.name || 'User' }}</p>
                                <p class="mt-1 text-sm text-white/65">{{ user.email || 'No email available' }}</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur">
                                <p class="text-xs uppercase tracking-[0.25em] text-white/50">Security</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ twoFactorEnabled ? 'Protected' : 'Open' }}</p>
                                <p class="mt-1 text-sm text-white/65">
                                    {{ twoFactorEnabled ? '2FA is active for sign-in.' : 'Enable 2FA for stronger account protection.' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
                    <aside class="lg:sticky lg:top-24 lg:self-start">
                        <div class="overflow-hidden rounded-[1.5rem] border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500 dark:text-slate-400">
                                    Work Type
                                </p>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                    Pick the section you want to edit.
                                </p>
                            </div>

                            <div class="p-3">
                                <button
                                    v-for="tab in tabs"
                                    :key="tab.key"
                                    type="button"
                                    class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left transition"
                                    :class="activeTab === tab.key
                                        ? 'bg-slate-950 text-white shadow-sm dark:bg-white dark:text-slate-950'
                                        : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800/70'"
                                    @click="setActiveTab(tab.key)"
                                >
                                    <span
                                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                                        :class="activeTab === tab.key ? 'bg-white/10 text-white dark:bg-slate-900 dark:text-slate-950' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300'"
                                    >
                                        <i :class="tab.icon" class="text-base" />
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold">
                                            {{ tab.label }}
                                        </span>
                                        <span
                                            class="block truncate text-xs"
                                            :class="activeTab === tab.key ? 'text-white/70 dark:text-slate-500' : 'text-slate-500 dark:text-slate-400'"
                                        >
                                            {{ tab.hint }}
                                        </span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </aside>

                    <div class="space-y-6">
                        <div v-if="activeTab === 'profile'" class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <UpdateProfileInformationForm
                                :must-verify-email="mustVerifyEmail"
                                :status="status"
                                class="max-w-none"
                            />
                        </div>

                        <div v-else-if="activeTab === 'password'" class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <UpdatePasswordForm class="max-w-none" />
                        </div>

                        <div v-else-if="activeTab === 'two-factor'" class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <TwoFactorForm class="max-w-none" />
                        </div>

                        <div v-else class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <DeleteUserForm class="max-w-none" />
                        </div>
                    </div>
                </section>

                <div class="rounded-[1.5rem] border border-slate-200 bg-gradient-to-br from-blue-600 to-cyan-500 p-5 text-white shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-[0.24em] text-white/70">
                        Quick Note
                    </h3>
                    <p class="mt-3 text-sm leading-6 text-white/85">
                        Keep your email verified and enable 2FA. That reduces account recovery risk and protects panel access.
                    </p>
                    <Link
                        v-if="route().has('security.manager')"
                        :href="route('security.manager')"
                        class="mt-4 inline-flex items-center rounded-xl border border-white/20 bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/15"
                    >
                        Open security console
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
