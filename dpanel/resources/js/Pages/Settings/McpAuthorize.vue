<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    requestId: { type: String, required: true },
    client: { type: Object, default: null },
    requestedScope: { type: String, default: 'read' },
    endpointUrl: { type: String, default: '' },
    groups: { type: Array, default: () => [] },
});

const groupId = ref('');

const scope = ref(props.requestedScope === 'full' ? 'full' : 'read');
const busy = ref(false);
const error = ref('');

const decide = async (approve) => {
    if (busy.value) return;
    busy.value = true;
    error.value = '';

    try {
        const { data } = await axios.post(panelRoute('mcp.oauth.decide', { requestId: props.requestId }), { approve, scope: scope.value, access_group_id: groupId.value || null });
        window.location.href = data.redirect;
    } catch (e) {
        error.value = e.response?.data?.message || t('settings.mcpAuthorize.failed');
        busy.value = false;
    }
};
</script>

<template>
    <Head :title="t('settings.mcpAuthorize.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-lg font-semibold">{{ t('settings.mcpAuthorize.title') }}</h1>
        </template>

        <div class="mx-auto max-w-lg">
            <div v-if="!client" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                {{ t('settings.mcpAuthorize.expired') }}
            </div>

            <div v-else class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <div class="flex items-center gap-3">
                    <i class="bi bi-plug text-2xl text-blue-600"></i>
                    <div>
                        <p class="text-base font-semibold">{{ t('settings.mcpAuthorize.wants', { name: client.name }) }}</p>
                        <p class="text-xs text-slate-500"><i18n-t keypath="settings.mcpAuthorize.returnTo" scope="global"><template #host><code>{{ client.redirect_host }}</code></template></i18n-t></p>
                    </div>
                </div>

                <p class="text-sm text-slate-600 dark:text-slate-300">
                    <i18n-t keypath="settings.mcpAuthorize.actAs" scope="global"><template #endpoint><code class="text-xs">{{ endpointUrl }}</code></template></i18n-t>
                </p>

                <div class="space-y-2">
                    <label class="flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm" :class="scope === 'read' ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/40' : 'border-slate-200 dark:border-slate-700'">
                        <input v-model="scope" type="radio" value="read" class="mt-0.5" />
                        <span><span class="font-medium">{{ t('settings.mcpAuthorize.readOnly') }}</span> — {{ t('settings.mcpAuthorize.readOnlyText') }}</span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm" :class="scope === 'full' ? 'border-amber-500 bg-amber-50 dark:bg-amber-950/40' : 'border-slate-200 dark:border-slate-700'">
                        <input v-model="scope" type="radio" value="full" class="mt-0.5" />
                        <span><span class="font-medium">{{ t('settings.mcpAuthorize.full') }}</span> — {{ t('settings.mcpAuthorize.fullText') }}</span>
                    </label>
                </div>

                <div v-if="groups.length">
                    <label class="mb-1 block text-sm font-medium">{{ t('settings.mcpAuthorize.tools') }}</label>
                    <select v-model="groupId" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option value="">{{ t('settings.mcpAuthorize.allTools') }}</option>
                        <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }} (t('settings.mcpAuthorize.toolsCount', { count: g.tools_count }))</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ t('settings.mcpAuthorize.groupHint') }}</p>
                </div>

                <div v-if="error" class="text-sm text-red-600">{{ error }}</div>

                <div class="flex justify-end gap-2">
                    <button @click="decide(false)" :disabled="busy" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">{{ t('settings.mcpAuthorize.deny') }}</button>
                    <button @click="decide(true)" :disabled="busy" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">{{ t('settings.mcpAuthorize.allow') }}</button>
                </div>

                <p class="text-xs text-slate-400">{{ t('settings.mcpAuthorize.disconnectHint') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
