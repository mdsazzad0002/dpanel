<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />

        <div class="space-y-6">
            <div class="space-y-2">
                <p class="font-mono text-xs uppercase tracking-[0.32em] text-emerald-300/80">
                    Email verification
                </p>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-50">
                    Verify your account
                </h2>
                <p class="text-sm leading-6 text-slate-300">
                    Before getting started, verify your email address with the link we sent.
                </p>
            </div>

            <div class="text-sm leading-6 text-slate-300">
                Thanks for signing up. If you did not receive the email, we can send another one.
            </div>
        </div>

        <div
            class="mt-4 rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm font-medium text-emerald-100"
            v-if="verificationLinkSent"
        >
            A new verification link has been sent to the email address you
            provided during registration.
        </div>

        <form class="mt-6" @submit.prevent="submit">
            <div class="flex items-center justify-between gap-4">
                <PrimaryButton
                    class="rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Resend Verification Email
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-slate-300 underline decoration-white/30 underline-offset-4 transition hover:text-slate-100 hover:decoration-emerald-300 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-[#07111d]"
                    >Log Out</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
