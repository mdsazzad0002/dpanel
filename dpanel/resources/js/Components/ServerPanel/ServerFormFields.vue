<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="md:col-span-2"><InputLabel for="name" value="Name" /><TextInput id="name" v-model="form.name" class="mt-1 block w-full" /><InputError :message="form.errors.name" class="mt-1" /></div>
    <div><InputLabel for="host" value="Host" /><TextInput id="host" v-model="form.host" class="mt-1 block w-full" /><InputError :message="form.errors.host" class="mt-1" /></div>
    <div><InputLabel for="port" value="Port" /><TextInput id="port" v-model="form.port" type="number" class="mt-1 block w-full" /><InputError :message="form.errors.port" class="mt-1" /></div>
    <div><InputLabel for="username" value="Username" /><TextInput id="username" v-model="form.username" class="mt-1 block w-full" /><InputError :message="form.errors.username" class="mt-1" /></div>
    <div>
        <InputLabel for="auth_type" value="Auth Type" />
        <select id="auth_type" v-model="form.auth_type" class="mt-1 block w-full rounded-md border-slate-300">
            <option value="password">Password</option>
            <option value="key">Private Key</option>
        </select>
        <InputError :message="form.errors.auth_type" class="mt-1" />
    </div>

    <div v-if="form.auth_type === 'password'" class="md:col-span-2"><InputLabel for="password" value="Password" /><TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" /><InputError :message="form.errors.password" class="mt-1" /></div>
    <div v-if="form.auth_type === 'key'" class="md:col-span-2">
        <InputLabel for="private_key" value="Private Key" />
        <textarea id="private_key" v-model="form.private_key" rows="8" class="mt-1 block w-full rounded-md border-slate-300" />
        <InputError :message="form.errors.private_key" class="mt-1" />
    </div>
    <div v-if="form.auth_type === 'key'" class="md:col-span-2"><InputLabel for="private_key_passphrase" value="Passphrase" /><TextInput id="private_key_passphrase" v-model="form.private_key_passphrase" type="password" class="mt-1 block w-full" /></div>

    <div>
        <InputLabel for="mode" value="Mode" />
        <select id="mode" v-model="form.mode" class="mt-1 block w-full rounded-md border-slate-300">
            <option value="setup">setup</option>
            <option value="normal">normal</option>
            <option value="emergency">emergency</option>
        </select>
    </div>
    <div class="md:col-span-2"><InputLabel for="notes" value="Notes" /><textarea id="notes" v-model="form.notes" rows="3" class="mt-1 block w-full rounded-md border-slate-300" /></div>
</template>
