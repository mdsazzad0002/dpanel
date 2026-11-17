<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    method: {
        type: String,
        required: true,
    },
    availableMethods: {
        type: Array,
        default: () => [],
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

const methodTabs = {
    email: {
        get label() { return t('auth.twoFactor.tabs.email.label'); },
        get hint() { return t('auth.twoFactor.tabs.email.hint'); },
        icon: 'bi bi-envelope',
    },
    google_auth_app: {
        get label() { return t('auth.twoFactor.tabs.app.label'); },
        get hint() { return t('auth.twoFactor.tabs.app.hint'); },
        icon: 'bi bi-shield-lock',
    },
    telegram: {
        label: 'Telegram',
        get hint() { return t('auth.twoFactor.tabs.telegram.hint'); },
        icon: 'bi bi-telegram',
    },
};

const activeMethod = ref(props.method);

watch(
    () => props.method,
    (value) => {
        activeMethod.value = value;
    },
    { immediate: true },
);

const availableTabs = computed(() => (props.availableMethods.length > 0
    ? props.availableMethods.filter((method) => Boolean(methodTabs[method]))
    : [props.method].filter((method) => Boolean(methodTabs[method]))));

const canResend = computed(() => ['email', 'telegram'].includes(activeMethod.value));

const form = useForm({
    code: '',
});

const selectMethod = (method) => {
    if (method === activeMethod.value) {
        return;
    }

    router.post(route('two-factor.method'), { method }, {
        preserveScroll: true,
    });
};

const submit = () => {
    form.post(route('two-factor.verify'), {
        preserveScroll: true,
        onSuccess: () => form.reset('code'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.twoFactor.headTitle')" />

        <div class="space-y-6">
            <div class="space-y-2">
                <p class="font-mono text-xs uppercase tracking-[0.32em] text-emerald-300/80">
                    {{ t('auth.twoFactor.eyebrow') }}
                </p>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-50">
                    {{ t('auth.twoFactor.title') }}
                </h2>
                <p class="text-sm leading-6 text-slate-300">
                    {{ t('auth.twoFactor.subtitle', { method: methodLabel }) }}
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <button
                    v-for="method in availableTabs"
                    :key="method"
                    type="button"
                    class="flex items-start gap-3 rounded-2xl border px-4 py-3 text-left transition"
                    :class="activeMethod === method
                        ? 'border-emerald-400/50 bg-emerald-400/10 text-slate-50'
                        : 'border-white/10 bg-white/[0.04] text-slate-300 hover:border-white/20 hover:bg-white/[0.06]'"
                    @click="selectMethod(method)"
                >
                    <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-black/20">
                        <i :class="methodTabs[method].icon" class="text-base" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold">
                            {{ methodTabs[method].label }}
                        </span>
                        <span class="block text-xs text-current/70">
                            {{ methodTabs[method].hint }}
                        </span>
                    </span>
                </button>
            </div>

            <div class="rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm text-slate-300">
                <p v-if="activeMethod === 'google_auth_app'">
                    {{ t('auth.twoFactor.appHint') }}
                </p>
                <p v-else-if="activeMethod === 'telegram'">
                    {{ t('auth.twoFactor.telegramHint') }}
                </p>
                <p v-else>
                    {{ t('auth.twoFactor.emailHint', { destination: maskedDestination || t('auth.twoFactor.defaultDestination') }) }}
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    {{ expiresIn === 1 ? t('auth.twoFactor.expiresOne') : t('auth.twoFactor.expires', { count: expiresIn }) }}
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <label for="code" class="mb-2 block text-sm font-medium text-slate-200">
                        {{ t('auth.twoFactor.code') }}
                    </label>
                    <TextInput
                        id="code"
                        v-model="form.code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        placeholder="123456"
                        class="challenge-input mt-0 block w-full rounded-xl border-white/10 bg-black text-center text-lg tracking-[0.5em] text-white transition placeholder:text-slate-500 focus:border-emerald-400 focus:ring-emerald-400"
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
                        {{ t('auth.twoFactor.back') }}
                    </Link>

                    <div class="flex items-center gap-3">
                        <Link
                            v-if="canResend"
                            :href="route('two-factor.resend')"
                            method="post"
                            as="button"
                            class="rounded-xl border border-white/10 px-4 py-3 text-sm font-medium text-slate-200 transition hover:border-emerald-400/40 hover:bg-white/[0.04]"
                        >
                            {{ t('auth.twoFactor.resend') }}
                        </Link>
                        <PrimaryButton
                            type="submit"
                            class="rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300"
                        >
                            {{ t('auth.twoFactor.verify') }}
                        </PrimaryButton>
                    </div>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
