<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'; import { computed } from 'vue'; import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'; import WebsiteTerminal from '@/Components/WebsiteTerminal.vue';
const props = defineProps({ website: { type: Object, required: true } }); const page = usePage(); const token = computed(() => String(page.props.panel?.token || '')); const panelRoute = (name, params = {}) => token.value ? route(name, { token: token.value, ...params }) : route(name, params);
</script>
<template>

    <Head :title="`Terminal - ${website.domain}`" />
    <AuthenticatedLayout><template #header>
            <div>
                <h1 class="text-lg font-semibold">Website Terminal</h1>
                <p class="text-sm text-slate-500">Terminal for {{ website.domain }}</p>
            </div>
        </template>
        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="panelRoute('websites.manage', { id: website.id })"
                    class="rounded-lg border px-4 py-2 text-sm dark:border-slate-700">Back to Manage</Link>
            </div>
            <WebsiteTerminal :website="website" />
        </div>
    </AuthenticatedLayout>
</template>
