<script setup>
// Site and administrator fields of an app installer. Which fields show comes
// from the app's installer class (definition().fields).
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    fields: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    generatePassword: { type: Function, required: true },
});
const model = defineModel({ type: Object, required: true });

const { t } = useI18n();
const showPassword = ref(false);
const has = (field) => props.fields.includes(field);
const textFields = computed(() => ['site_name', 'admin_name', 'admin_username', 'admin_email'].filter(has));
const inputClass = 'mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <div>
        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('apps.fields.heading') }}</p>
        <div class="mt-2 grid gap-3 sm:grid-cols-2">
            <label v-for="field in textFields" :key="field" class="text-sm">
                {{ t(`apps.fields.${field}`) }}
                <input v-model="model[field]" :type="field === 'admin_email' ? 'email' : 'text'" :autocomplete="field === 'admin_username' ? 'off' : undefined" :disabled="disabled" :class="inputClass" />
            </label>
            <label v-if="has('admin_password')" class="text-sm sm:col-span-2">
                {{ t('apps.fields.admin_password') }} <span class="text-xs text-slate-500">{{ t('apps.fields.passwordHint') }}</span>
                <div class="flex gap-2">
                    <input v-model="model.admin_password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" :disabled="disabled" :class="inputClass" class="font-mono" />
                    <button type="button" class="mt-1 rounded-md border border-slate-300 px-3 text-xs dark:border-slate-700" @click="showPassword = !showPassword">{{ showPassword ? t('apps.fields.hide') : t('apps.fields.show') }}</button>
                    <button type="button" class="mt-1 rounded-md border border-slate-300 px-3 text-xs dark:border-slate-700" :disabled="disabled" @click="model.admin_password = generatePassword(); showPassword = true">{{ t('apps.fields.generate') }}</button>
                </div>
            </label>
        </div>
    </div>
</template>
