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

        <section class="relative overflow-hidden rounded-2xl border border-emerald-500/30 bg-[#07110f]/95 p-6 shadow-[0_0_35px_rgba(16,185,129,0.18)] backdrop-blur sm:p-8">
            <div class="pointer-events-none absolute inset-0 opacity-20 [background-image:linear-gradient(to_right,#10b98133_1px,transparent_1px),linear-gradient(to_bottom,#10b98133_1px,transparent_1px)] [background-size:24px_24px]" />
            <div class="pointer-events-none absolute -bottom-10 left-0 right-0 h-px bg-gradient-to-r from-transparent via-emerald-400 to-transparent" />

            <div class="relative font-mono">
                <p class="text-xs uppercase tracking-[0.24em] text-emerald-400">Secure Shell Gateway</p>
                <h1 class="mt-2 text-2xl font-bold text-emerald-100">ServerPanel Access</h1>
                <p class="mt-2 text-sm text-emerald-200/80">
                    Authenticate to manage hosts, deployments, and system operations.
                </p>
            </div>

            <div
                v-if="status"
                class="relative mt-5 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-200"
            >
                {{ status }}
            </div>

            <form class="relative mt-6 space-y-5 font-mono" @submit.prevent="submit">
                <div>
                    <InputLabel for="email" value="Admin Email" class="text-emerald-300" />
                    <TextInput
                        id="email"
                        type="email"
                        class="mt-2 block w-full rounded-xl border-emerald-600/40 bg-[#030807] text-emerald-100 transition placeholder:text-emerald-300/40 focus:border-emerald-400 focus:ring-emerald-400"
                        v-model="form.email"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="root@host.local"
                    />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <InputLabel for="password" value="Access Key" class="text-emerald-300" />
                    <TextInput
                        id="password"
                        type="password"
                        class="mt-2 block w-full rounded-xl border-emerald-600/40 bg-[#030807] text-emerald-100 transition placeholder:text-emerald-300/40 focus:border-emerald-400 focus:ring-emerald-400"
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
                        <span class="ms-2 text-sm text-emerald-200/80">Persist session</span>
                    </label>
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-sm font-medium text-emerald-300 hover:text-emerald-200"
                    >
                        Forgot password?
                    </Link>
                </div>

                <PrimaryButton
                    type="submit"
                    class="w-full justify-center rounded-xl bg-emerald-500 py-3 text-sm font-semibold tracking-[0.14em] text-[#032019] transition hover:bg-emerald-400 focus:bg-emerald-500"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'AUTHENTICATING...' : 'LOGIN TO SERVER' }}
                </PrimaryButton>
            </form>
        </section>
    </GuestLayout>
</template>
