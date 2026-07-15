<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { computed, ref, watch } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});
const twoFactor = computed(() => page.props.twoFactor ?? {});
const status = computed(() => String(page.props.status ?? ''));
const emailStatus = ref(String(page.props.status ?? ''));
const isEmailVerified = computed(() => Boolean(user.value?.email_verified_at));
const emailEnabled = computed(() => Boolean(twoFactor.value.enabled ?? false) && String(twoFactor.value.method ?? '') === 'email');
const googleEnabled = computed(() => Boolean(twoFactor.value.enabled ?? false) && String(twoFactor.value.method ?? '') === 'google_auth_app');
const googleSecret = computed(() => String(twoFactor.value.secret ?? ''));
const googleProvisioningUri = computed(() => String(twoFactor.value.provisioning_uri ?? ''));

const form = useForm({
    enabled: Boolean(twoFactor.value.enabled ?? false),
    method: String(twoFactor.value.method ?? 'google_auth_app'),
    confirmation_code: '',
});

const emailCode = ref('');
const googleCode = ref('');
const qrCodeDataUrl = ref('');
const emailSending = ref(false);
const emailError = ref('');

const loadQrCode = async (uri) => {
    if (!uri) {
        qrCodeDataUrl.value = '';
        return;
    }

    try {
        qrCodeDataUrl.value = await QRCode.toDataURL(uri, {
            errorCorrectionLevel: 'M',
            margin: 1,
            width: 240,
            color: {
                dark: '#0f172a',
                light: '#ffffff',
            },
        });
    } catch {
        qrCodeDataUrl.value = '';
    }
};

watch(
    () => googleProvisioningUri.value,
    (uri) => {
        if (googleEnabled.value) {
            loadQrCode(uri);
            return;
        }

        qrCodeDataUrl.value = '';
    },
    { immediate: true },
);

watch(
    () => status.value,
    (value) => {
        emailStatus.value = value;
    },
    { immediate: true },
);

const submitSecurityAction = (method, enabled, codeRef) => {
    form.enabled = enabled;
    form.method = method;
    form.confirmation_code = String(codeRef.value ?? '');
    form.patch(route('profile.two-factor.update'), {
        preserveScroll: true,
        onSuccess: () => {
            codeRef.value = '';
            form.confirmation_code = '';
        },
    });
};

const enableGoogleAuth = () => {
    submitSecurityAction('google_auth_app', true, googleCode);
};

const enableEmailVerification = () => {
    submitSecurityAction('email', true, emailCode);
};

const disableGoogleAuth = () => {
    submitSecurityAction('google_auth_app', false, googleCode);
};

const disableEmailVerification = () => {
    submitSecurityAction('email', false, emailCode);
};

const requestEmailCode = () => {
    emailSending.value = true;
    emailError.value = '';

    window.axios
        .patch(route('profile.two-factor.update'), {
            enabled: true,
            method: 'email',
            confirmation_code: '',
        }, {
            headers: {
                Accept: 'application/json',
            },
        })
        .then((response) => {
            emailStatus.value = String(response?.data?.status ?? 'email-code-sent');
            emailCode.value = '';
        })
        .catch((error) => {
            emailError.value = String(error?.response?.data?.errors?.confirmation_code?.[0] ?? error?.response?.data?.message ?? 'Unable to send verification email.');
        })
        .finally(() => {
            emailSending.value = false;
        });
};

const refreshGoogleQr = async () => {
    await loadQrCode(googleProvisioningUri.value);
};
</script>

<template>
    <section class="space-y-6">
        <header class="space-y-2">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                Security
            </h2>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Use the verification cards below to manage your two-factor settings.
            </p>
        </header>

        <div class="grid gap-4 md:grid-cols-[4fr_8fr]">
            <article class="flex h-full flex-col rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <i class="bi bi-envelope text-lg" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                                Email Verification
                            </h3>
                            <span
                                class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.2em]"
                                :class="isEmailVerified ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300'"
                            >
                                {{ isEmailVerified ? 'Verified' : 'Pending' }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                            Send a verification email to confirm your account address.
                        </p>
                    </div>
                </div>

                <div
                v-if="emailStatus === 'verification-link-sent'"
                class="mt-5 flex-1 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950/30 dark:text-slate-200">

                    <p

                        class="mt-2 text-sm font-medium text-emerald-600 dark:text-emerald-400"
                    >
                        Verification email sent.
                    </p>
                </div>

                <div class="mt-5">
                    <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">
                        Confirmation Code
                    </label>
                    <input
                        v-model="emailCode"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        placeholder="Enter code"
                        class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    />
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        {{ emailStatus === 'email-code-sent' ? 'Code sent to your email address.' : 'Use the code sent to your email to confirm enable or disable.' }}
                    </p>
                    <p
                        v-if="emailError"
                        class="mt-2 text-xs text-red-600 dark:text-red-400"
                    >
                        {{ emailError }}
                    </p>
                </div>

                <div class="mt-5 flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        :disabled="form.processing || emailSending"
                        @click="requestEmailCode"
                    >
                        Send Email
                    </button>

                    <button
                        v-if="!emailEnabled"
                        type="button"
                        class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700"
                        :disabled="form.processing"
                        @click="enableEmailVerification"
                    >
                        Enable
                    </button>

                    <button
                        v-else
                        type="button"
                        class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        :disabled="form.processing"
                        @click="disableEmailVerification"
                    >
                        Disable
                    </button>

                </div>
                <p
                    v-if="form.errors.confirmation_code"
                    class="mt-2 text-xs text-red-600 dark:text-red-400"
                >
                    {{ form.errors.confirmation_code }}
                </p>
            </article>

            <article class="flex h-full flex-col rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <i class="bi bi-shield-lock text-lg" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                                Google Authenticator
                            </h3>
                            <span
                                class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.2em]"
                                :class="googleEnabled ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300'"
                            >
                                {{ googleEnabled ? 'Enabled' : 'Ready' }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                            Scan the QR code with Google Authenticator or another TOTP app.
                        </p>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950/30 dark:text-slate-200">
                        <p class="font-semibold text-slate-900 dark:text-slate-100">Confirmation</p>
                        <label class="mt-4 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">
                            Confirmation Code
                        </label>
                        <input
                            v-model="googleCode"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            placeholder="Enter authenticator code"
                            class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        />
                        <p
                            v-if="form.errors.confirmation_code"
                            class="mt-2 text-xs text-red-600 dark:text-red-400"
                        >
                            {{ form.errors.confirmation_code }}
                        </p>
                        <p
                            v-if="status === 'google-code-required'"
                            class="mt-2 text-xs text-blue-600 dark:text-blue-400"
                        >
                            Scan the QR code, then enter the app code to enable Google Authenticator.
                        </p>

                        <div class="mt-5 flex items-center gap-3">
                            <PrimaryButton
                                v-if="!googleEnabled"
                                :class="{ 'opacity-25': form.processing }"
                                :disabled="form.processing"
                                @click="enableGoogleAuth"
                            >
                                Enable
                            </PrimaryButton>

                            <button
                                v-else
                                type="button"
                                class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                                :disabled="form.processing"
                                @click="disableGoogleAuth"
                            >
                                Disable
                            </button>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950/30 dark:text-slate-200">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-semibold text-slate-900 dark:text-slate-100">QR code</p>
                            <button
                                v-if="!googleEnabled"
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                                :disabled="form.processing"
                                @click="refreshGoogleQr"
                            >
                                <i class="bi bi-arrow-clockwise" />
                                Retry
                            </button>
                        </div>

                        <div class="mt-4">
                            <div
                                v-if="!googleEnabled"
                                class="flex justify-center rounded-2xl bg-white p-4 dark:bg-slate-900"
                            >
                                <img
                                    v-if="qrCodeDataUrl"
                                    :src="qrCodeDataUrl"
                                    alt="Google Authenticator QR code"
                                    class="h-52 w-52 rounded-xl border border-slate-200 bg-white p-2 dark:border-slate-800"
                                />
                                <div
                                    v-else
                                    class="flex h-52 w-52 items-center justify-center rounded-xl border border-dashed border-slate-300 text-center text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400"
                                >
                                    Enable Google Authenticator to generate the QR code.
                                </div>
                            </div>
                            <div
                                v-else
                                class="flex min-h-52 items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400"
                            >
                                Google Authenticator is already enabled, so the QR code is hidden.
                            </div>
                        </div>
                        <p
                            v-if="!googleEnabled && googleSecret"
                            class="mt-4 break-all rounded-xl bg-white px-3 py-2 font-mono text-xs dark:bg-slate-900"
                        >
                            {{ googleSecret }}
                        </p>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
