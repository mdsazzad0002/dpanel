<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const twoFactor = computed(() => page.props.twoFactor ?? {});
const availableMethods = computed(() => twoFactor.value.available_methods ?? []);
const globalPolicy = computed(() => twoFactor.value.global_policy ?? {});

const methodMeta = {
    email: {
        title: 'Email code',
        description: 'Send a one-time code to the account email address.',
    },
    telegram: {
        title: 'Telegram code',
        description: 'Deliver the login code to the configured Telegram chat.',
    },
    google_auth_app: {
        title: 'Authenticator app',
        description: 'Use a time-based code from Google Authenticator or a compatible app.',
    },
};

const form = useForm({
    enabled: Boolean(twoFactor.value.enabled ?? false),
    method: twoFactor.value.method || (availableMethods.value[0] ?? 'email'),
    telegram_chat_id: twoFactor.value.telegram_chat_id ?? '',
});

const isMethodAvailable = (method) => availableMethods.value.includes(method);

const methodCards = computed(() => availableMethods.value.map((method) => ({
    key: method,
    ...methodMeta[method],
})));

const isEnabled = computed(() => Boolean(form.enabled));
const activeMethodLabel = computed(() => methodMeta[form.method]?.title ?? 'Not configured');

const save = () => {
    form.patch(route('profile.two-factor.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <section class="space-y-6">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                    Two-Factor Authentication
                </h2>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    Enable an extra verification step for your account login.
                </p>
            </div>

            <span
                class="inline-flex w-fit items-center rounded-full px-3 py-1 text-xs font-semibold"
                :class="isEnabled ? 'bg-emerald-500/10 text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'"
            >
                {{ isEnabled ? '2FA enabled' : '2FA disabled' }}
            </span>
        </header>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(280px,0.8fr)]">
            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 dark:border-slate-800 dark:bg-slate-950/30">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">
                                Enable 2FA
                            </p>
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                Turn this on to require an extra code during sign-in.
                            </p>
                        </div>

                        <label class="relative inline-flex cursor-pointer items-center">
                            <input v-model="form.enabled" type="checkbox" class="peer sr-only" />
                            <span class="h-7 w-12 rounded-full bg-slate-300 transition peer-checked:bg-blue-600 dark:bg-slate-700" />
                            <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
                        </label>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-slate-100">
                            Verification method
                        </p>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                            Choose the method used for second-step verification.
                        </p>
                    </div>

                    <div class="grid gap-3">
                        <button
                            v-for="method in methodCards"
                            :key="method.key"
                            type="button"
                            class="flex w-full items-start gap-3 rounded-2xl border p-4 text-left transition"
                            :class="form.method === method.key
                                ? 'border-blue-500 bg-blue-50 shadow-sm dark:border-blue-400 dark:bg-blue-950/30'
                                : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-slate-700 dark:hover:bg-slate-800/70'"
                            @click="form.method = method.key"
                        >
                            <span class="mt-1 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <i
                                    class="text-base"
                                    :class="{
                                        'bi bi-envelope': method.key === 'email',
                                        'bi bi-telegram': method.key === 'telegram',
                                        'bi bi-shield-lock': method.key === 'google_auth_app',
                                    }"
                                />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ method.title }}
                                </span>
                                <span class="mt-1 block text-sm text-slate-600 dark:text-slate-300">
                                    {{ method.description }}
                                </span>
                            </span>

                            <span
                                class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.2em]"
                                :class="form.method === method.key
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300'"
                            >
                                {{ form.method === method.key ? 'Selected' : 'Available' }}
                            </span>
                        </button>
                    </div>

                    <InputError class="mt-2" :message="form.errors.method" />
                </div>

                <div
                    v-if="form.method === 'telegram'"
                    class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"
                >
                    <InputLabel for="telegram_chat_id" value="Telegram Chat ID" />
                    <TextInput
                        id="telegram_chat_id"
                        v-model="form.telegram_chat_id"
                        type="text"
                        class="mt-2 block w-full"
                        autocomplete="off"
                        placeholder="123456789"
                    />
                    <InputError class="mt-2" :message="form.errors.telegram_chat_id" />
                </div>
            </div>

            <aside class="space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500 dark:text-slate-400">
                        Current status
                    </p>
                    <p class="mt-2 text-lg font-semibold text-slate-900 dark:text-slate-100">
                        {{ isEnabled ? 'Protection active' : 'Protection off' }}
                    </p>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        {{ isEnabled ? `Using ${activeMethodLabel}.` : 'Enable 2FA to secure future sign-ins.' }}
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950/30 dark:text-slate-200">
                    <p class="font-semibold text-slate-900 dark:text-slate-100">Global policy</p>
                    <div class="mt-3 space-y-2 text-sm">
                        <p>Email: {{ (globalPolicy.email ?? true) ? 'enabled' : 'disabled' }}</p>
                        <p>Telegram: {{ (globalPolicy.telegram ?? false) ? 'enabled' : 'disabled' }}</p>
                        <p>Authenticator app: {{ (globalPolicy.google_auth_app ?? true) ? 'enabled' : 'disabled' }}</p>
                    </div>
                </div>

                <div
                    v-if="form.method === 'google_auth_app' && !twoFactor.secret"
                    class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200"
                >
                    A secret will be generated when you save this form. Scan it with Google Authenticator or a compatible app.
                </div>

                <div
                    v-if="twoFactor.secret"
                    class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-700 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"
                >
                    <p class="font-semibold text-slate-900 dark:text-slate-100">Authenticator secret</p>
                    <p class="mt-2 break-all rounded-xl bg-slate-50 px-3 py-2 font-mono text-xs dark:bg-slate-950/40">
                        {{ twoFactor.secret }}
                    </p>
                    <p v-if="twoFactor.provisioning_uri" class="mt-3 break-all text-xs text-slate-500 dark:text-slate-400">
                        Provisioning URI: {{ twoFactor.provisioning_uri }}
                    </p>
                </div>
            </aside>
        </div>

        <div class="flex items-center gap-3">
            <PrimaryButton
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
                @click="save"
            >
                Save 2FA Settings
            </PrimaryButton>

            <p class="text-sm text-slate-500 dark:text-slate-400">
                Changes apply to the next login.
            </p>
        </div>
    </section>
</template>
