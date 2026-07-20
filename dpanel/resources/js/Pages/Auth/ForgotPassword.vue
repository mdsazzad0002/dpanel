<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <div class="space-y-6">
            <div class="space-y-2">
                <p class="font-mono text-xs uppercase tracking-[0.32em] text-emerald-300/80">
                    Password recovery
                </p>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-50">
                    Reset access
                </h2>
                <p class="text-sm leading-6 text-slate-300">
                    Enter your email address and we will send a reset link.
                </p>
            </div>

            <div class="text-sm leading-6 text-slate-300">
                Forgot your password? No problem. We will email you a password reset link that lets you choose a new one.
            </div>
        </div>

        <div
            v-if="status"
            class="mt-4 rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm font-medium text-emerald-100"
        >
            {{ status }}
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" class="text-slate-200" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-2 block w-full rounded-xl border-white/10 bg-white/[0.04] text-slate-100 transition placeholder:text-slate-400/70 focus:border-emerald-400 focus:ring-emerald-400"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="flex items-center justify-end">
                <PrimaryButton
                    class="rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Email Password Reset Link
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
