<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ServerFormFields from '@/Components/ServerPanel/ServerFormFields.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    server: { type: Object, default: null },
    editing: { type: Boolean, default: false },
});

const form = useForm({
    name: props.server?.name ?? '',
    host: props.server?.host ?? '',
    port: props.server?.port ?? 22,
    username: props.server?.username ?? '',
    auth_type: props.server?.auth_type ?? 'password',
    password: '',
    private_key: '',
    private_key_passphrase: '',
    mode: props.server?.mode ?? 'setup',
    notes: props.server?.notes ?? '',
});

const submit = () => {
    if (props.editing && props.server) {
        form.patch(route('servers.update', props.server.id));
        return;
    }

    form.post(route('servers.store'));
};
</script>

<template>
    <Head :title="editing ? 'Edit Server' : 'Add Server'" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-lg font-semibold">{{ editing ? 'Edit Server' : 'Add Server' }}</h1>
        </template>

        <div class="mx-auto max-w-3xl rounded-xl border border-slate-200 bg-white p-6">
            <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
                Credentials are encrypted and never shown again.
            </p>

            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submit">
                <ServerFormFields :form="form" />

                <div class="md:col-span-2 flex justify-end">
                    <PrimaryButton :disabled="form.processing">{{ editing ? 'Update Server' : 'Save Server' }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
