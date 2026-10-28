<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

const props = defineProps({
    user: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    impersonation: { type: Object, default: null },
    theme: { type: String, default: 'light' },
    appName: { type: String, default: 'dPanel' },
    appVersion: { type: String, default: '' },
    panelRoute: { type: Function, required: true },
});

const emit = defineEmits(['toggle-theme']);

const userName = computed(() => String(props.user?.name ?? 'User'));
const userEmail = computed(() => String(props.user?.email ?? ''));
const userInitials = computed(() => userName.value
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || 'U');
const twoFactorEnabled = computed(() => Boolean(props.user?.two_factor_enabled));
const primaryRole = computed(() => props.roles[0] ?? 'user');

const roleStyles = {
    admin: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
    reseller: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
};
const roleClass = (role) => roleStyles[role] ?? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';

// Profile/Edit.vue reads ?tab= so each link lands on the right tab.
const profileTab = (tab) => props.panelRoute('profile.edit', { tab });

const emailCopied = ref(false);
const copyEmail = async () => {
    if (!userEmail.value) return;
    try {
        await navigator.clipboard.writeText(userEmail.value);
        emailCopied.value = true;
        setTimeout(() => { emailCopied.value = false; }, 1500);
    } catch {
        // Clipboard permissions can be denied silently — the email stays visible to copy by hand.
    }
};
</script>

<template>
    <Dropdown align="right" width="72">
        <template #trigger>
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm font-medium text-slate-700 transition-all hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-slate-600 dark:hover:bg-slate-800"
                aria-label="Account menu"
            >
                <span
                    class="relative inline-flex h-7 w-7 items-center justify-center rounded-full text-[10px] font-semibold text-white shadow-sm ring-2 ring-white dark:ring-slate-900"
                    :class="impersonation?.active ? 'bg-gradient-to-br from-indigo-500 to-purple-600' : 'bg-gradient-to-br from-blue-600 to-indigo-600'"
                >
                    {{ userInitials }}
                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-400 dark:border-slate-900"></span>
                </span>
                <span class="hidden max-w-[10rem] text-left leading-tight sm:block">
                    <span class="block truncate text-xs font-semibold text-slate-900 dark:text-slate-100">{{ userName }}</span>
                    <span class="block truncate text-[10px] capitalize text-slate-500 dark:text-slate-400">
                        {{ impersonation?.active ? 'Viewing as user' : primaryRole }}
                    </span>
                </span>
                <i class="bi bi-chevron-down text-[10px] text-slate-400"></i>
            </button>
        </template>

        <template #content>
            <!-- Profile Header -->
            <div class="border-b border-slate-200 px-4 py-4 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <span class="relative inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-indigo-600 text-sm font-bold text-white shadow-lg">
                        {{ userInitials }}
                        <span class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white bg-emerald-400 dark:border-slate-900"></span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ userName }}</p>
                        <button
                            v-if="userEmail"
                            type="button"
                            class="group flex max-w-full items-center gap-1 text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                            :title="emailCopied ? 'Copied' : 'Copy email'"
                            @click.stop="copyEmail"
                        >
                            <span class="truncate">{{ userEmail }}</span>
                            <i :class="['bi shrink-0 text-[10px]', emailCopied ? 'bi-check2 text-emerald-500' : 'bi-copy opacity-0 group-hover:opacity-100']"></i>
                        </button>
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <span
                                v-for="role in (roles.length ? roles : ['No role'])"
                                :key="role"
                                class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-medium capitalize"
                                :class="roleClass(role)"
                            >
                                {{ role }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 2FA status -->
                <Link
                    :href="profileTab('two-factor')"
                    class="mt-3 flex items-center justify-between rounded-lg px-3 py-2 text-xs font-medium transition-colors"
                    :class="twoFactorEnabled
                        ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/20 dark:text-emerald-300 dark:hover:bg-emerald-900/30'
                        : 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-900/20 dark:text-amber-300 dark:hover:bg-amber-900/30'"
                >
                    <span class="flex items-center gap-2">
                        <i :class="['bi', twoFactorEnabled ? 'bi-shield-check' : 'bi-shield-exclamation']"></i>
                        {{ twoFactorEnabled ? 'Two-factor is on' : 'Two-factor is off' }}
                    </span>
                    <span v-if="!twoFactorEnabled" class="font-semibold">Enable <i class="bi bi-arrow-right"></i></span>
                </Link>
            </div>

            <!-- Account -->
            <div class="py-2">
                <p class="px-4 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Account</p>
                <DropdownLink :href="profileTab('profile')">
                    <i class="bi bi-person mr-3 text-slate-400"></i>
                    <span>My Profile</span>
                </DropdownLink>
                <DropdownLink :href="profileTab('password')">
                    <i class="bi bi-key mr-3 text-slate-400"></i>
                    <span>Change Password</span>
                </DropdownLink>
                <DropdownLink v-if="route().has('security.manager')" :href="panelRoute('security.manager')">
                    <i class="bi bi-shield-lock mr-3 text-slate-400"></i>
                    <span>Security</span>
                </DropdownLink>
                <DropdownLink v-if="route().has('self-connection.manager')" :href="panelRoute('self-connection.manager')">
                    <i class="bi bi-hdd-network mr-3 text-slate-400"></i>
                    <span>Database & Redis</span>
                </DropdownLink>
                <DropdownLink v-if="route().has('mcp.tokens.index')" :href="panelRoute('mcp.tokens.index')">
                    <i class="bi bi-plug mr-3 text-slate-400"></i>
                    <span>MCP Access</span>
                </DropdownLink>
            </div>

            <!-- Appearance -->
            <div class="border-t border-slate-200 py-2 dark:border-slate-700">
                <button
                    type="button"
                    role="switch"
                    :aria-checked="theme === 'dark'"
                    class="flex w-full items-center justify-between px-4 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                    @click.stop="emit('toggle-theme')"
                >
                    <span class="flex items-center">
                        <i :class="['bi mr-3 text-slate-400', theme === 'dark' ? 'bi-moon-stars' : 'bi-sun']"></i>
                        Dark mode
                    </span>
                    <span
                        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                        :class="theme === 'dark' ? 'bg-blue-600' : 'bg-slate-300'"
                    >
                        <span
                            class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform"
                            :class="theme === 'dark' ? 'translate-x-4' : 'translate-x-0.5'"
                        ></span>
                    </span>
                </button>
            </div>

            <!-- Session -->
            <div class="border-t border-slate-200 py-2 dark:border-slate-700">
                <DropdownLink
                    v-if="impersonation?.active"
                    :href="panelRoute('impersonation.stop')"
                    method="post"
                    as="button"
                    class="text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-900/20"
                >
                    <i class="bi bi-person-bounding-box mr-3"></i>
                    <span>Back to {{ impersonation.admin_name || 'admin' }}</span>
                </DropdownLink>
                <DropdownLink :href="panelRoute('logout')" method="post" as="button" class="text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                    <i class="bi bi-box-arrow-right mr-3"></i>
                    <span>Log Out</span>
                </DropdownLink>
            </div>

            <div v-if="appVersion" class="border-t border-slate-200 px-4 py-2 text-[10px] text-slate-400 dark:border-slate-700">
                {{ appName }} v{{ appVersion }}
            </div>
        </template>
    </Dropdown>
</template>
