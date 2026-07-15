<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
});

const isFocused = ref(null);
</script>

<template>
    <section>
        <form
            @submit.prevent="form.patch(route('profile.update'))"
            class="space-y-6"
        >
            <!-- Name Field -->
            <div>
                <InputLabel for="name" value="Full Name" />
                <div class="relative mt-2">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="bi bi-person text-slate-400"></i>
                    </div>
                    <input
                        id="name"
                        type="text"
                        v-model="form.name"
                        required
                        autofocus
                        autocomplete="name"
                        @focus="isFocused = 'name'"
                        @blur="isFocused = null"
                        :class="[
                            'block w-full rounded-xl border bg-slate-50 py-2.5 pl-10 pr-4 text-sm text-slate-900 transition-all placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-900',
                            form.errors.name ? 'border-red-500' : 'border-slate-200',
                            isFocused === 'name' ? 'border-blue-500 ring-2 ring-blue-500/20' : ''
                        ]"
                    />
                </div>
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <!-- Email Field (Read Only) -->
            <div>
                <InputLabel value="Email Address" />
                <div class="relative mt-2">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="bi bi-envelope text-slate-400"></i>
                    </div>
                    <input
                        type="email"
                        :value="user.email"
                        disabled
                        class="block w-full rounded-xl border border-slate-200 bg-slate-100 py-2.5 pl-10 pr-4 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400"
                    />
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                        <i class="bi bi-lock text-slate-400"></i>
                    </div>
                </div>
                <p class="mt-2 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                    <i class="bi bi-info-circle"></i>
                    Email address cannot be changed from here
                </p>
            </div>

            <!-- Email Verification -->
            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                <div class="flex items-start gap-3">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-900/50 dark:text-amber-400">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-amber-800 dark:text-amber-200">Email not verified</p>
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                            Please verify your email address to access all features.
                        </p>
                        <Link
                            :href="route('verification.send')"
                            method="post"
                            as="button"
                            class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-amber-700"
                        >
                            <i class="bi bi-send"></i>
                            Resend verification email
                        </Link>
                    </div>
                </div>
                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-3 flex items-center gap-2 rounded-lg bg-emerald-100 px-3 py-2 text-xs font-medium text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400"
                >
                    <i class="bi bi-check-circle-fill"></i>
                    Verification link sent to your email
                </div>
            </div>

            <!-- Verified Badge -->
            <div v-else-if="user.email_verified_at" class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-900/50 dark:text-emerald-400">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">Email verified</p>
                    <p class="text-xs text-emerald-700 dark:text-emerald-300">Your email address has been verified</p>
                </div>
            </div>

            <!-- Save Button -->
            <div class="flex items-center gap-4 pt-2">
                <PrimaryButton
                    :disabled="form.processing"
                    class="inline-flex items-center gap-2 rounded-xl px-5 py-2.5"
                >
                    <i v-if="form.processing" class="bi bi-arrow-repeat animate-spin"></i>
                    <i v-else class="bi bi-check-lg"></i>
                    {{ form.processing ? 'Saving...' : 'Save Changes' }}
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
                        Saved successfully
                    </div>
                </Transition>
            </div>
        </form>
    </section>
</template>
