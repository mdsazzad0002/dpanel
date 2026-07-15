<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    method: {
        type: String,
        required: true,
    },
    methodLabel: {
        type: String,
        required: true,
    },
    maskedDestination: {
        type: String,
        default: '',
    },
    expiresIn: {
        type: Number,
        default: 10,
    },
});

const form = useForm({
    code: '',
});

const submit = () => {
    form.post(route('two-factor.verify'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Two-Factor Verification" />

        <div class="space-y-6">
            <div class="space-y-2">
                <p class="font-mono text-xs uppercase tracking-[0.32em] text-emerald-300/80">
                    Two-factor auth
                </p>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-50">
                    Verify your login
                </h2>
                <p class="text-sm leading-6 text-slate-300">
                    Complete sign in using {{ methodLabel }}.
                </p>
            </div>

            <div class="rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm text-slate-300">
                <p v-if="method === 'google_auth_app'">
                    Open your authenticator app and enter the 6-digit code.
                </p>
                <p v-else>
                    We sent a 6-digit code to {{ maskedDestination || 'your configured destination' }}.
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    The code expires in {{ expiresIn }} minute{{ expiresIn === 1 ? '' : 's' }}.
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <label for="code" class="mb-2 block text-sm font-medium text-slate-200">
                        Verification Code
                    </label>
                    <TextInput
                        id="code"
                        v-model="form.code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        placeholder="123456"
                        class="mt-0 block w-full rounded-xl border-white/10 bg-white/[0.04] text-center text-lg tracking-[0.5em] text-slate-100 transition placeholder:text-slate-500 focus:border-emerald-400 focus:ring-emerald-400"
                        required
                        autofocus
                    />
                    <InputError class="mt-2" :message="form.errors.code" />
                </div>

                <div class="flex items-center justify-between gap-4">
                    <Link
                        :href="route('login')"
                        class="rounded-md text-sm text-slate-300 underline decoration-white/30 underline-offset-4 transition hover:text-slate-100 hover:decoration-emerald-300"
                    >
                        Back to login
                    </Link>

                    <div class="flex items-center gap-3">
                        <Link
                            v-if="method !== 'google_auth_app'"
                            :href="route('two-factor.resend')"
                            method="post"
                            as="button"
                            class="rounded-xl border border-white/10 px-4 py-3 text-sm font-medium text-slate-200 transition hover:border-emerald-400/40 hover:bg-white/[0.04]"
                        >
                            Resend
                        </Link>
                        <PrimaryButton
                            type="submit"
                            class="rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300"
                        >
                            Verify
                        </PrimaryButton>
                    </div>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
