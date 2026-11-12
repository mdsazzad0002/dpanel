<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CreateApiTokenForm from './Partials/CreateApiTokenForm.vue';
import ApiTokenList from './Partials/ApiTokenList.vue';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    scopes: { type: Array, default: () => [] },
    baseUrl: { type: String, required: true },
    docsUrl: { type: String, required: true },
    maxDays: { type: Number, default: 3650 },
});
const { t } = useI18n();

const tokenList = ref([...props.tokens]);
const added = (token) => tokenList.value.unshift(token);
const removed = (id) => { tokenList.value = tokenList.value.filter((token) => token.id !== id); };
</script>

<template>
    <Head :title="t('apiTokens.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('apiTokens.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('apiTokens.subtitle') }}</p>
            </div>
        </template>

        <div class="max-w-4xl space-y-4">
            <div class="rounded-2xl border border-slate-200/80 bg-white p-4 text-sm shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                <dl class="grid gap-2 sm:grid-cols-[10rem_1fr]">
                    <dt class="text-slate-500">{{ t('apiTokens.baseUrl') }}</dt>
                    <dd class="break-all font-mono text-xs">{{ baseUrl }}</dd>
                    <dt class="text-slate-500">{{ t('apiTokens.docs') }}</dt>
                    <dd><a :href="docsUrl" target="_blank" rel="noopener" class="text-blue-600 hover:underline dark:text-blue-400">OpenAPI (YAML)</a></dd>
                    <dt class="text-slate-500">{{ t('apiTokens.example') }}</dt>
                    <dd class="break-all font-mono text-xs">curl -H "Authorization: Bearer dp-api-…" {{ baseUrl }}/websites</dd>
                </dl>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ t('apiTokens.limits') }}</p>
            </div>

            <CreateApiTokenForm :scopes="scopes" :max-days="maxDays" @created="added" />
            <ApiTokenList :tokens="tokenList" @revoked="removed" />
        </div>
    </AuthenticatedLayout>
</template>
