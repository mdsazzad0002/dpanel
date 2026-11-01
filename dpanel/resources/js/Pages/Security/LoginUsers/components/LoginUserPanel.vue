<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    user: { type: Object, default: null },
    shells: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    pending: { type: String, default: '' },
    error: { type: String, default: '' },
    notice: { type: String, default: '' },
});
const emit = defineEmits(['close', 'action']);

const shell = ref('');
const password = ref('');
const showPassword = ref(false);

// Keyed on the name, so a list refresh doesn't wipe a password being typed.
watch(() => props.user?.name, () => {
    password.value = '';
    showPassword.value = false;
}, { immediate: true });
watch(() => props.user?.shell, (value) => { shell.value = value || ''; }, { immediate: true });
// A successful password change clears the field.
watch(() => props.pending, (now, before) => {
    if (before === 'set_password' && now === '' && !props.error) password.value = '';
});

const isRoot = computed(() => Number(props.user?.uid) === 0);
const loginShells = computed(() => props.shells.filter((item) => !/nologin$/.test(item)));
const nologinShell = computed(() => props.shells.find((item) => /nologin$/.test(item)) || '/usr/sbin/nologin');

const generatePassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^*-_=+';
    const bytes = crypto.getRandomValues(new Uint32Array(20));
    password.value = Array.from(bytes, (value) => chars[value % chars.length]).join('');
    showPassword.value = true;
};

const act = (action, extra = {}, confirmText = '') => {
    if (confirmText && !confirm(confirmText)) return;
    emit('action', { action, user: props.user.name, ...extra });
};
</script>

<template>
    <Teleport to="body">
        <div v-if="user" class="fixed inset-0 z-50">
            <button type="button" aria-label="Close user panel" class="absolute inset-0 bg-slate-950/50" @click="emit('close')" />
            <aside class="absolute inset-y-0 right-0 flex w-full flex-col bg-white shadow-2xl sm:w-[30rem] dark:bg-slate-900">
                <header class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <div class="min-w-0">
                        <h2 class="truncate font-semibold text-slate-900 dark:text-white"><i class="bi bi-person-lock mr-1"></i>{{ user.name }}</h2>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">uid {{ user.uid }} · {{ user.home }}</p>
                        <p v-if="busy" class="mt-0.5 text-[11px] text-blue-600 dark:text-blue-400"><i class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>Applying change on the server…</p>
                    </div>
                    <button type="button" class="rounded-lg px-3 py-2 text-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" @click="emit('close')">×</button>
                </header>

                <div class="flex-1 space-y-5 overflow-y-auto p-5 text-sm">
                    <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
                    <div v-if="notice" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</div>
                    <!-- Login shell -->
                    <section class="space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <h3 class="font-semibold text-slate-800 dark:text-slate-200">Shell login</h3>
                        <p class="text-xs text-slate-500">Blocking login sets the shell to nologin: SSH (password or key) and su stop working. The user's websites and cron jobs keep running.</p>
                        <div class="flex gap-2">
                            <select v-model="shell" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800">
                                <option v-for="item in shells" :key="item" :value="item" :disabled="isRoot && /nologin$/.test(item)">{{ item }}</option>
                            </select>
                            <button type="button" :disabled="busy || shell === user.shell" class="rounded-lg bg-blue-600 px-3 py-2 font-medium text-white hover:bg-blue-700 disabled:opacity-50" @click="act('set_shell', { shell })"><i v-if="pending === 'set_shell'" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ pending === 'set_shell' ? 'Saving…' : 'Save' }}</button>
                        </div>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <button v-if="user.can_login && !isRoot" type="button" :disabled="busy" class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 disabled:opacity-50 dark:border-red-900 dark:bg-red-950 dark:text-red-300" @click="act('set_shell', { shell: nologinShell }, `Block shell login for ${user.name}?`)">
                                <i :class="pending === 'set_shell' ? 'bi bi-arrow-repeat inline-block animate-spin' : 'bi bi-slash-circle'" class="mr-1"></i>Block login
                            </button>
                            <button v-if="!user.can_login && loginShells.length" type="button" :disabled="busy" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-50 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300" @click="act('set_shell', { shell: loginShells.includes('/bin/bash') ? '/bin/bash' : loginShells[0] })">
                                <i :class="pending === 'set_shell' ? 'bi bi-arrow-repeat inline-block animate-spin' : 'bi bi-check2-circle'" class="mr-1"></i>Allow login (bash)
                            </button>
                        </div>
                        <p v-if="isRoot" class="text-[11px] text-amber-600">root always keeps a login shell so the server stays recoverable.</p>
                    </section>

                    <!-- Password -->
                    <section class="space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-slate-800 dark:text-slate-200">Password</h3>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="user.password_locked ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' : (user.has_password ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300')">
                                {{ user.password_locked ? 'Locked' : (user.has_password ? 'Set' : 'No password') }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-500">Locking disables password login only; SSH keys still work while the shell allows login.</p>
                        <div class="flex gap-2">
                            <div class="relative min-w-0 flex-1">
                                <input v-model="password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" placeholder="New password (min 6)" class="w-full rounded-lg border border-slate-300 px-3 py-2 pr-9 dark:border-slate-700 dark:bg-slate-800" />
                                <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400" :aria-label="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword"><i :class="showPassword ? 'bi bi-eye-slash' : 'bi bi-eye'"></i></button>
                            </div>
                            <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-slate-700" title="Generate a strong password" @click="generatePassword"><i class="bi bi-magic"></i></button>
                            <button type="button" :disabled="busy || password.length < 6" class="rounded-lg bg-blue-600 px-3 py-2 font-medium text-white hover:bg-blue-700 disabled:opacity-50" @click="act('set_password', { password })"><i v-if="pending === 'set_password'" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ pending === 'set_password' ? 'Saving…' : 'Set' }}</button>
                        </div>
                        <button v-if="!user.password_locked" type="button" :disabled="busy" class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-900 dark:text-red-300" @click="act('lock', {}, `Lock password login for ${user.name}?`)">
                            <i :class="pending === 'lock' ? 'bi bi-arrow-repeat inline-block animate-spin' : 'bi bi-lock'" class="mr-1"></i>{{ pending === 'lock' ? 'Locking…' : 'Lock password' }}
                        </button>
                        <button v-else type="button" :disabled="busy" class="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:border-emerald-900 dark:text-emerald-300" @click="act('unlock')">
                            <i :class="pending === 'unlock' ? 'bi bi-arrow-repeat inline-block animate-spin' : 'bi bi-unlock'" class="mr-1"></i>{{ pending === 'unlock' ? 'Unlocking…' : 'Unlock password' }}
                        </button>
                    </section>

                    <!-- Sessions -->
                    <section class="space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-slate-800 dark:text-slate-200">Active sessions</h3>
                            <span class="text-xs text-slate-500">{{ user.sessions }} open · {{ user.ssh_keys }} SSH key(s)</span>
                        </div>
                        <button type="button" :disabled="busy || isRoot || !user.sessions" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-100 disabled:opacity-50 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300" @click="act('end_sessions', {}, `End all login sessions of ${user.name}?`)">
                            <i :class="pending === 'end_sessions' ? 'bi bi-arrow-repeat inline-block animate-spin' : 'bi bi-box-arrow-right'" class="mr-1"></i>{{ pending === 'end_sessions' ? 'Ending…' : 'End sessions' }}
                        </button>
                    </section>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
