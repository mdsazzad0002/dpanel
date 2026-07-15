<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    if (form.processing) {
        return;
    }

    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <section class="space-y-6">
            <div class="space-y-2">
                <p class="font-mono text-xs uppercase tracking-[0.32em] text-emerald-300/80">
                    Admin portal
                </p>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-50 sm:text-3xl">
                    Sign in to dPanel
                </h2>
                <p class="text-sm leading-6 text-slate-300">
                    Use your administrative credentials to continue.
                </p>
            </div>

            <div
                v-if="status"
                class="rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-100"
            >
                {{ status }}
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <InputLabel for="email" value="Admin Email" class="text-slate-200" />
                    <TextInput
                        id="email"
                        type="email"
                        class="login-input mt-2 block w-full rounded-xl border-white/10 bg-black text-white transition placeholder:text-slate-400/70 focus:border-emerald-400 focus:ring-emerald-400"
                        v-model="form.email"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="root@host.local"
                    />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <InputLabel for="password" value="Access Key" class="text-slate-200" />
                    <TextInput
                        id="password"
                        type="password"
                        class="login-input mt-2 block w-full rounded-xl border-white/10 bg-black text-white transition placeholder:text-slate-400/70 focus:border-emerald-400 focus:ring-emerald-400"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        placeholder="Enter secure credential"
                    />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex cursor-pointer items-center">
                        <Checkbox name="remember" v-model:checked="form.remember" />
                        <span class="ms-2 text-sm text-slate-300">Persist session</span>
                    </label>
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-sm font-medium text-emerald-300 transition hover:text-emerald-200"
                    >
                        Forgot password?
                    </Link>
                </div>

                <PrimaryButton
                    type="submit"
                    class="w-full justify-center rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 py-3 text-sm font-semibold tracking-[0.14em] text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300 focus:bg-emerald-400"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'AUTHENTICATING...' : 'LOGIN TO SERVER' }}
                </PrimaryButton>
            </form>
        </section>
    </GuestLayout>
</template>
