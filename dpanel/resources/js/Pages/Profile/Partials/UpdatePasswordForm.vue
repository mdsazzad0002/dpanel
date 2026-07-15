<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);
const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const showConfirmPassword = ref(false);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const passwordStrength = computed(() => {
    const password = form.password;
    if (!password) return { score: 0, label: '', color: '' };

    let score = 0;
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
    if (/\d/.test(password)) score++;
    if (/[^a-zA-Z0-9]/.test(password)) score++;

    if (score <= 2) return { score: 20, label: 'Weak', color: 'bg-red-500', textColor: 'text-red-600 dark:text-red-400' };
    if (score <= 3) return { score: 50, label: 'Fair', color: 'bg-amber-500', textColor: 'text-amber-600 dark:text-amber-400' };
    if (score <= 4) return { score: 75, label: 'Good', color: 'bg-blue-500', textColor: 'text-blue-600 dark:text-blue-400' };
    return { score: 100, label: 'Strong', color: 'bg-emerald-500', textColor: 'text-emerald-600 dark:text-emerald-400' };
});

const passwordsMatch = computed(() => {
    if (!form.password_confirmation) return null;
    return form.password === form.password_confirmation;
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <form @submit.prevent="updatePassword" class="space-y-6">
            <!-- Current Password -->
            <div>
                <InputLabel for="current_password" value="Current Password" />
                <div class="relative mt-2">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="bi bi-key text-slate-400"></i>
                    </div>
                    <input
                        id="current_password"
                        ref="currentPasswordInput"
                        v-model="form.current_password"
                        :type="showCurrentPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        class="block w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-10 text-sm text-slate-900 transition-all placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-900"
                    />
                    <button
                        type="button"
                        @click="showCurrentPassword = !showCurrentPassword"
                        class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                    >
                        <i :class="['bi', showCurrentPassword ? 'bi-eye-slash' : 'bi-eye']"></i>
                    </button>
                </div>
                <InputError :message="form.errors.current_password" class="mt-2" />
            </div>

            <!-- New Password -->
            <div>
                <InputLabel for="password" value="New Password" />
                <div class="relative mt-2">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="bi bi-shield-lock text-slate-400"></i>
                    </div>
                    <input
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        :type="showNewPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        class="block w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-10 text-sm text-slate-900 transition-all placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-900"
                    />
                    <button
                        type="button"
                        @click="showNewPassword = !showNewPassword"
                        class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                    >
                        <i :class="['bi', showNewPassword ? 'bi-eye-slash' : 'bi-eye']"></i>
                    </button>
                </div>
                <InputError :message="form.errors.password" class="mt-2" />

                <!-- Password Strength Indicator -->
                <div v-if="form.password" class="mt-3">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Password strength</span>
                        <span :class="['text-xs font-medium', passwordStrength.textColor]">{{ passwordStrength.label }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div
                            :class="[passwordStrength.color, 'h-full rounded-full transition-all duration-500']"
                            :style="{ width: passwordStrength.score + '%' }"
                        ></div>
                    </div>
                    <div class="mt-2 grid grid-cols-4 gap-1">
                        <div :class="[passwordStrength.score >= 25 ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-700', 'h-1 rounded-full transition-colors']"></div>
                        <div :class="[passwordStrength.score >= 50 ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-700', 'h-1 rounded-full transition-colors']"></div>
                        <div :class="[passwordStrength.score >= 75 ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-700', 'h-1 rounded-full transition-colors']"></div>
                        <div :class="[passwordStrength.score >= 100 ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-700', 'h-1 rounded-full transition-colors']"></div>
                    </div>
                </div>
            </div>

            <!-- Confirm Password -->
            <div>
                <InputLabel for="password_confirmation" value="Confirm Password" />
                <div class="relative mt-2">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="bi bi-check-circle text-slate-400"></i>
                    </div>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :type="showConfirmPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        class="block w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-10 text-sm text-slate-900 transition-all placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-900"
                    />
                    <button
                        type="button"
                        @click="showConfirmPassword = !showConfirmPassword"
                        class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                    >
                        <i :class="['bi', showConfirmPassword ? 'bi-eye-slash' : 'bi-eye']"></i>
                    </button>
                </div>
                <InputError :message="form.errors.password_confirmation" class="mt-2" />

                <!-- Match Indicator -->
                <div v-if="form.password_confirmation" class="mt-2 flex items-center gap-2">
                    <i
                        :class="[
                            'bi',
                            passwordsMatch ? 'bi-check-circle-fill text-emerald-500' : 'bi-x-circle-fill text-red-500'
                        ]"
                    ></i>
                    <span :class="['text-xs', passwordsMatch ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400']">
                        {{ passwordsMatch ? 'Passwords match' : 'Passwords do not match' }}
                    </span>
                </div>
            </div>

            <!-- Save Button -->
            <div class="flex items-center gap-4 pt-2">
                <PrimaryButton
                    :disabled="form.processing"
                    class="inline-flex items-center gap-2 rounded-xl px-5 py-2.5"
                >
                    <i v-if="form.processing" class="bi bi-arrow-repeat animate-spin"></i>
                    <i v-else class="bi bi-shield-check"></i>
                    {{ form.processing ? 'Updating...' : 'Update Password' }}
                </PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out duration-300"
                    enter-from-class="opacity-0 translate-x-2"
                    enter-to-class="opacity-100 translate-x-0"
                    leave-active-class="transition ease-in-out duration-300"
                    leave-from-class="opacity-100 translate-x-0"
                    leave-to-class="opacity-0 translate-x-2"
                >
                    <div v-if="form.recentlySuccessful" class="flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
                        <i class="bi bi-check-circle-fill"></i>
                        Password updated successfully
                    </div>
                </Transition>
            </div>
        </form>
    </section>
</template>
