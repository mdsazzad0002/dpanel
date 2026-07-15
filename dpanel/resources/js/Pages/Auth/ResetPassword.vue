<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Reset Password" />

        <div class="space-y-6">
            <div class="space-y-2">
                <p class="font-mono text-xs uppercase tracking-[0.32em] text-emerald-300/80">
                    Reset password
                </p>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-50">
                    Choose a new password
                </h2>
                <p class="text-sm leading-6 text-slate-300">
                    Use the email address tied to your account and set a new secure password.
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="submit">
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

            <div>
                <InputLabel for="password" value="Password" class="text-slate-200" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-2 block w-full rounded-xl border-white/10 bg-white/[0.04] text-slate-100 transition placeholder:text-slate-400/70 focus:border-emerald-400 focus:ring-emerald-400"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel
                    for="password_confirmation"
                    value="Confirm Password"
                    class="text-slate-200"
                />

                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-2 block w-full rounded-xl border-white/10 bg-white/[0.04] text-slate-100 transition placeholder:text-slate-400/70 focus:border-emerald-400 focus:ring-emerald-400"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError
                    class="mt-2"
                    :message="form.errors.password_confirmation"
                />
            </div>

            <div class="flex items-center justify-end">
                <PrimaryButton
                    class="rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Reset Password
                </PrimaryButton>
            </div>
            </form>
        </div>
    </GuestLayout>
</template>
