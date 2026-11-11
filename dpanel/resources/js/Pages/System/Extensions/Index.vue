<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AddExtension from './components/AddExtension.vue';
import CredentialsNotice from './components/CredentialsNotice.vue';
import ExtensionCard from './components/ExtensionCard.vue';

// System → Extensions (backlog U7). An extension runs elsewhere and acts
// through its own scoped API token; see docs/extensions.md.
const props = defineProps({
    extensions: { type: Array, default: () => [] },
    apiBase: { type: String, default: '' },
    events: { type: Array, default: () => [] },
});
const { t } = useI18n();

const extensions = ref(props.extensions);
const credentials = ref(null);
const notice = ref('');

const saved = ({ extension, credentials: shown, message }) => {
    extensions.value = [extension, ...extensions.value.filter((item) => item.id !== extension.id)].sort((a, b) => a.name.localeCompare(b.name));
    notice.value = message || '';
    if (shown) credentials.value = { name: extension.name, ...shown };
};
const changed = (extension) => {
    extensions.value = extensions.value.map((item) => (item.id === extension.id ? extension : item));
};
const removed = (id) => {
    extensions.value = extensions.value.filter((item) => item.id !== id);
};
const rotated = ({ extension, credentials: shown }) => {
    changed(extension);
    credentials.value = { name: extension.name, ...shown };
};
</script>

<template>
    <Head :title="t('extensions.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('extensions.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('extensions.help') }}</p>
            </div>
        </template>

        <div class="max-w-5xl space-y-4">
            <div v-if="notice" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</div>
            <CredentialsNotice v-if="credentials" :credentials="credentials" :api-base="apiBase" @close="credentials = null" />

            <section class="space-y-3">
                <p v-if="!extensions.length" class="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700">{{ t('extensions.empty') }}</p>
                <ExtensionCard v-for="extension in extensions" :key="extension.id" :extension="extension" @changed="changed" @removed="removed" @rotated="rotated" />
            </section>

            <AddExtension :events="events" @saved="saved" />
        </div>
    </AuthenticatedLayout>
</template>
