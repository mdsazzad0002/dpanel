<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';
import { readDeviceLocation } from '@/composables/deviceLocation';

const props = defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    locationMode: {
        type: String,
        default: 'ask',
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

// The admin picks off / ask / required per protocol on the Login Audit page;
// the server sends the mode for the protocol this page was opened on.
const askLocation = props.locationMode !== 'off';
const locationRequired = props.locationMode === 'required';
const locating = ref(false);

const submit = async () => {
    if (form.processing) {
        return;
    }

    form.clearErrors();
    form.processing = true;

    let location = {};
    if (askLocation) {
        locating.value = true;
        try {
            location = await readDeviceLocation();
        } catch (error) {
            // In "ask" mode a refusal is fine; the IP location is still saved.
            if (locationRequired) {
                form.setError('location', error.message);
                form.processing = false;
                return;
            }
        } finally {
            locating.value = false;
        }
    }

    try {
        const response = await axios.post('/login', { ...form.data(), ...location }, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        await new Promise((resolve) => setTimeout(resolve, 700));
        const target = response?.data?.redirect;
        if (typeof target !== 'string' || target === '') {
            form.setError('email', 'Login succeeded but no redirect was returned. Please try again.');
            return;
        }
        window.location.assign(target);
    } catch (error) {
        const data = error?.response?.data || {};
        Object.entries(data.errors || {}).forEach(([key, messages]) => {
            const field = key.startsWith('device_') ? 'location' : key;
            form.setError(field, Array.isArray(messages) ? messages[0] : messages);
        });
    } finally {
        form.processing = false;
        form.reset('password');
    }
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
                        class="login-input mt-2 block w-full rounded-xl border-white/10 bg-black text-slate-300 transition placeholder:text-slate-500 focus:border-emerald-400 focus:ring-emerald-400"
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
                        class="login-input mt-2 block w-full rounded-xl border-white/10 bg-black text-slate-300 transition placeholder:text-slate-500 focus:border-emerald-400 focus:ring-emerald-400"
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

                <div v-if="askLocation" class="space-y-2">
                    <p class="flex items-start gap-2 text-xs leading-5 text-slate-400">
                        <i class="bi bi-geo-alt mt-0.5 text-emerald-300"></i>
                        <span>
                            Your device location is recorded with each sign-in for the security audit. The browser will ask for permission.
                            <template v-if="locationRequired"> Signing in needs it.</template>
                        </span>
                    </p>
                    <InputError :message="form.errors.location" />
                </div>

                <PrimaryButton
                    type="submit"
                    class="w-full justify-center rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 py-3 text-sm font-semibold tracking-[0.14em] text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:from-emerald-300 hover:to-cyan-300 focus:bg-emerald-400"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ locating ? 'GETTING LOCATION...' : (form.processing ? 'AUTHENTICATING...' : 'LOGIN TO SERVER') }}
                </PrimaryButton>
            </form>
        </section>
    </GuestLayout>
</template>
