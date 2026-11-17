<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.register.submit')" />

        <div class="space-y-6">
            <div class="space-y-2">
                <p class="font-mono text-xs uppercase tracking-[0.32em] text-emerald-300/80">
                    {{ t('auth.register.eyebrow') }}
                </p>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-50">
                    {{ t('auth.register.title') }}
                </h2>
                <p class="text-sm leading-6 text-slate-300">
                    {{ t('auth.register.subtitle') }}
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="name" :value="t('auth.register.name')" class="text-slate-200" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-2 block w-full rounded-xl border-white/10 bg-white/[0.04] text-slate-100 transition placeholder:text-slate-400/70 focus:border-emerald-400 focus:ring-emerald-400"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" :value="t('auth.register.email')" class="text-slate-200" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-2 block w-full rounded-xl border-white/10 bg-white/[0.04] text-slate-100 transition placeholder:text-slate-400/70 focus:border-emerald-400 focus:ring-emerald-400"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" :value="t('auth.register.password')" class="text-slate-200" />

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
                    :value="t('auth.register.confirm')"
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

            <div class="flex items-center justify-between gap-4">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm text-slate-300 underline decoration-white/30 underline-offset-4 transition hover:text-slate-100 hover:decoration-emerald-300 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-[#07111d]"
                >
                    {{ t('auth.register.already') }}
                </Link>

                <PrimaryButton
                    class="rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ t('auth.register.submit') }}
                </PrimaryButton>
            </div>
            </form>
        </div>
    </GuestLayout>
</template>
