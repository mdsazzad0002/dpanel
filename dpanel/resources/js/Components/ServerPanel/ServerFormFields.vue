<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="md:col-span-2"><InputLabel for="name" :value="t('serverPanel.form.name')" /><TextInput id="name" v-model="form.name" class="mt-1 block w-full" /><InputError :message="form.errors.name" class="mt-1" /></div>
    <div><InputLabel for="host" :value="t('serverPanel.form.host')" /><TextInput id="host" v-model="form.host" class="mt-1 block w-full" /><InputError :message="form.errors.host" class="mt-1" /></div>
    <div><InputLabel for="port" :value="t('serverPanel.form.port')" /><TextInput id="port" v-model="form.port" type="number" class="mt-1 block w-full" /><InputError :message="form.errors.port" class="mt-1" /></div>
    <div><InputLabel for="username" :value="t('serverPanel.form.username')" /><TextInput id="username" v-model="form.username" class="mt-1 block w-full" /><InputError :message="form.errors.username" class="mt-1" /></div>
    <div>
        <InputLabel for="auth_type" :value="t('serverPanel.form.authType')" />
        <select id="auth_type" v-model="form.auth_type" class="mt-1 block w-full rounded-md border-slate-300">
            <option value="password">{{ t('serverPanel.form.password') }}</option>
            <option value="key">{{ t('serverPanel.form.privateKey') }}</option>
        </select>
        <InputError :message="form.errors.auth_type" class="mt-1" />
    </div>

    <div v-if="form.auth_type === 'password'" class="md:col-span-2"><InputLabel for="password" :value="t('serverPanel.form.password')" /><TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" /><InputError :message="form.errors.password" class="mt-1" /></div>
    <div v-if="form.auth_type === 'key'" class="md:col-span-2">
        <InputLabel for="private_key" :value="t('serverPanel.form.privateKey')" />
        <textarea id="private_key" v-model="form.private_key" rows="8" class="mt-1 block w-full rounded-md border-slate-300" />
        <InputError :message="form.errors.private_key" class="mt-1" />
    </div>
    <div v-if="form.auth_type === 'key'" class="md:col-span-2"><InputLabel for="private_key_passphrase" :value="t('serverPanel.form.passphrase')" /><TextInput id="private_key_passphrase" v-model="form.private_key_passphrase" type="password" class="mt-1 block w-full" /></div>

    <div>
        <InputLabel for="mode" :value="t('serverPanel.form.mode')" />
        <select id="mode" v-model="form.mode" class="mt-1 block w-full rounded-md border-slate-300">
            <option value="setup">{{ t('serverPanel.form.modes.setup') }}</option>
            <option value="normal">{{ t('serverPanel.form.modes.normal') }}</option>
            <option value="emergency">{{ t('serverPanel.form.modes.emergency') }}</option>
        </select>
    </div>
    <div class="md:col-span-2"><InputLabel for="notes" :value="t('serverPanel.form.notes')" /><textarea id="notes" v-model="form.notes" rows="3" class="mt-1 block w-full rounded-md border-slate-300" /></div>
</template>
