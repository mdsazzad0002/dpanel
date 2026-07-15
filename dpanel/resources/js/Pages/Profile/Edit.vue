<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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
const twoFactorMethod = computed(() => String(twoFactor.value.method ?? 'email').replace(/_/g, ' ') || 'email');
const userInitials = computed(() => String(user.value?.name ?? 'User')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || 'U');

// Profile completion calculation
const profileCompletion = computed(() => {
    let score = 0;
    if (user.value?.name) score += 30;
    if (user.value?.email) score += 30;
    if (emailVerified.value) score += 20;
    if (twoFactorEnabled.value) score += 20;
    return score;
});

let verificationPoll = null;

const stopVerificationPolling = () => {
    if (verificationPoll !== null) {
        clearInterval(verificationPoll);
        verificationPoll = null;
    }
};

const refreshVerificationState = () => {
    if (emailVerified.value) {
        stopVerificationPolling();
        return;
    }

    router.reload({
        only: ['auth'],
        preserveScroll: true,
        preserveState: true,
    });
};

watch(
    emailVerified,
    (verified) => {
        if (verified) {
            stopVerificationPolling();
            return;
        }

        if (verificationPoll === null) {
            verificationPoll = window.setInterval(refreshVerificationState, 5000);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    stopVerificationPolling();
});

const tabs = [
    {
        key: 'profile',
        label: 'Profile',
        hint: 'Name and email',
        icon: 'bi-person-vcard',
    },
    {
        key: 'password',
        label: 'Password',
        hint: 'Change password',
        icon: 'bi-shield-lock',
    },
    {
        key: 'two-factor',
        label: 'Security',
        hint: '2FA settings',
        icon: 'bi-phone-lock',
    },
];

const activeTab = ref('profile');
const setActiveTab = (tab) => {
    activeTab.value = tab;
};

const getCompletionColor = () => {
    if (profileCompletion.value >= 80) return 'from-emerald-500 to-emerald-400';
    if (profileCompletion.value >= 50) return 'from-amber-500 to-amber-400';
    return 'from-red-500 to-red-400';
};
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Profile</h1>
                <p class="truncate text-sm text-slate-500 dark:text-slate-400">Manage your account settings</p>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Profile Header Card -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                <!-- Cover/Gradient Header -->
                <div class="relative bg-gradient-to-r from-blue-600 via-indigo-600 to-violet-600 px-6 py-8">
                    <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg%20width%3D%2260%22%20height%3D%2260%22%20viewBox%3D%220%200%2060%2060%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Cg%20fill%3D%22none%22%20fill-rule%3D%22evenodd%22%3E%3Cg%20fill%3D%22%23ffffff%22%20fill-opacity%3D%220.05%22%3E%3Cpath%20d%3D%22M36%2034v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6%2034v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6%204V0H4v4H0v2h4v4h2V6h4V4H6z%22%2F%3E%3C%2Fg%3E%3C%2Fg%3E%3C%2Fsvg%3E')] opacity-50"></div>

                    <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center">
                        <!-- Avatar -->
                        <div class="relative">
                            <div class="flex h-24 w-24 items-center justify-center rounded-2xl bg-white/20 text-2xl font-bold text-white shadow-xl ring-4 ring-white/30 backdrop-blur-sm">
                                {{ userInitials }}
                            </div>
                            <button class="absolute -bottom-1 -right-1 flex h-8 w-8 items-center justify-center rounded-full bg-white text-slate-700 shadow-lg transition-transform hover:scale-110">
                                <i class="bi bi-camera text-sm"></i>
                            </button>
                        </div>

                        <!-- User Info -->
                        <div class="flex-1 text-white">
                            <h2 class="text-2xl font-bold">{{ user.name || 'User' }}</h2>
                            <p class="mt-1 text-white/80">{{ user.email }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="inline-flex items-center gap-1 rounded-full bg-white/20 px-3 py-1 text-xs font-medium backdrop-blur-sm">
                                    <i class="bi bi-person-badge"></i>
                                    {{ roles.join(', ') || 'User' }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium backdrop-blur-sm"
                                    :class="emailVerified ? 'bg-emerald-500/30 text-emerald-100' : 'bg-amber-500/30 text-amber-100'"
                                >
                                    <i :class="emailVerified ? 'bi bi-check-circle-fill' : 'bi bi-exclamation-circle-fill'"></i>
                                    {{ emailVerified ? 'Verified' : 'Unverified' }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium backdrop-blur-sm"
                                    :class="twoFactorEnabled ? 'bg-cyan-500/30 text-cyan-100' : 'bg-white/20 text-white/80'"
                                >
                                    <i class="bi bi-shield-check"></i>
                                    {{ twoFactorEnabled ? '2FA On' : '2FA Off' }}
                                </span>
                            </div>
                        </div>

                        <!-- Profile Completion -->
                        <div class="rounded-xl bg-white/10 p-4 backdrop-blur-sm sm:text-right">
                            <p class="text-xs font-medium uppercase tracking-wider text-white/70">Profile Completion</p>
                            <div class="mt-2 flex items-center gap-3">
                                <div class="relative h-16 w-16">
                                    <svg class="h-16 w-16 -rotate-90" viewBox="0 0 100 100">
                                        <circle cx="50" cy="50" r="40" fill="none" stroke="currentColor" stroke-width="8" class="text-white/20" />
                                        <circle
                                            cx="50" cy="50" r="40" fill="none" stroke-width="8" stroke-linecap="round"
                                            class="text-white transition-all duration-1000"
                                            :stroke="'currentColor'"
                                            :stroke-dasharray="2 * Math.PI * 40"
                                            :stroke-dashoffset="2 * Math.PI * 40 - (profileCompletion / 100) * 2 * Math.PI * 40"
                                        />
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <span class="text-sm font-bold text-white">{{ profileCompletion }}%</span>
                                    </div>
                                </div>
                                <div class="hidden text-left sm:block">
                                    <p class="text-lg font-bold text-white">{{ profileCompletion }}%</p>
                                    <p class="text-xs text-white/70">Complete</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="grid grid-cols-3 border-t border-slate-200 dark:border-slate-700">
                    <div class="border-r border-slate-200 px-4 py-4 text-center dark:border-slate-700">
                        <i class="bi bi-envelope-check text-lg text-slate-400"></i>
                        <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ emailVerified ? 'Verified' : 'Pending' }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Email Status</p>
                    </div>
                    <div class="border-r border-slate-200 px-4 py-4 text-center dark:border-slate-700">
                        <i class="bi bi-shield-exclamation text-lg text-slate-400"></i>
                        <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ twoFactorEnabled ? 'Enabled' : 'Disabled' }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">2FA Status</p>
                    </div>
                    <div class="px-4 py-4 text-center">
                        <i class="bi bi-person-badge text-lg text-slate-400"></i>
                        <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ roles[0] || 'User' }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Role</p>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="flex gap-1 rounded-xl border border-slate-200 bg-slate-100 p-1 dark:border-slate-700 dark:bg-slate-800">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    @click="setActiveTab(tab.key)"
                    :class="[
                        activeTab === tab.key
                            ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-slate-100'
                            : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200',
                        'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all'
                    ]"
                >
                    <i :class="['bi', tab.icon]"></i>
                    <span class="hidden sm:inline">{{ tab.label }}</span>
                </button>
            </div>

            <!-- Tab Content -->
            <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-900">
                <!-- Profile Tab -->
                <div v-if="activeTab === 'profile'">
                    <div class="mb-6 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400">
                            <i class="bi bi-person-vcard text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Profile Information</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Update your account details</p>
                        </div>
                    </div>
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-none"
                    />
                </div>

                <!-- Password Tab -->
                <div v-else-if="activeTab === 'password'">
                    <div class="mb-6 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-400">
                            <i class="bi bi-shield-lock text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Update Password</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Ensure your account stays secure</p>
                        </div>
                    </div>
                    <UpdatePasswordForm class="max-w-none" />
                </div>

                <!-- Security Tab -->
                <div v-else-if="activeTab === 'two-factor'">
                    <div class="mb-6 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400">
                            <i class="bi bi-phone-lock text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Two-Factor Authentication</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Add extra security to your account</p>
                        </div>
                    </div>
                    <TwoFactorForm class="max-w-none" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
