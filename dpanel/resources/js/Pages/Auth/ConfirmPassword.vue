<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirm Password" />

        <div class="space-y-6">
            <div class="space-y-2">
                <p class="font-mono text-xs uppercase tracking-[0.32em] text-emerald-300/80">
                    Security check
                </p>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-50">
                    Confirm password
                </h2>
                <p class="text-sm leading-6 text-slate-300">
                    Please confirm your password before continuing.
                </p>
            </div>

            <div class="text-sm leading-6 text-slate-300">
                This is a secure area of the application and we need to verify your identity first.
            </div>
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="password" value="Password" class="text-slate-200" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-2 block w-full rounded-xl border-white/10 bg-white/[0.04] text-slate-100 transition placeholder:text-slate-400/70 focus:border-emerald-400 focus:ring-emerald-400"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="flex justify-end">
                <PrimaryButton
                    class="rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Confirm
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
