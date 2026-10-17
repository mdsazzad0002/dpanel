<script setup>
import { computed, inject, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: { type: Object, required: true },
    parent: { type: Object, default: null },
});

const pushToast = inject('pushToast', () => {});
const { panelRoute, requestJson } = usePanelApi();
const busy = ref('');
const editing = ref(false);
const domain = ref(props.website.domain);

const visitUrl = computed(() => `${props.website.enable_ssl ? 'https' : 'http'}://${props.website.domain}`);

const saveDomain = async () => {
    if (busy.value) return;
    busy.value = 'save';
    try {
        const data = await requestJson(panelRoute('websites.alias.update', { id: props.website.id }), {
            method: 'PATCH',
            body: { domain: domain.value.trim() },
        });
        pushToast(data.message, 'success');
        editing.value = false;
        router.reload({ only: ['website', 'sslStatus'] });
    } catch (error) {
        pushToast(error.message || 'Alias update failed.');
    } finally {
        busy.value = '';
    }
};

const removeAlias = async () => {
    if (busy.value || !window.confirm(`Remove alias ${props.website.domain}?`)) return;
    busy.value = 'remove';
    try {
        await requestJson(panelRoute('websites.destroy', { id: props.website.id }), { method: 'DELETE' });
        router.visit(props.parent
            ? panelRoute('websites.manage', { id: props.parent.id })
            : panelRoute('websites.list'));
    } catch (error) {
        pushToast(error.message || 'Alias removal failed.');
        busy.value = '';
    }
};
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Alias Domain</h2>

        <div v-if="editing" class="mt-4 flex gap-2">
            <input v-model="domain" type="text"
                class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"
                @keyup.enter="saveDomain" />
            <button type="button" :disabled="Boolean(busy)" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm text-white disabled:opacity-60" @click="saveDomain">
                {{ busy === 'save' ? 'Saving…' : 'Save' }}
            </button>
            <button type="button" class="rounded-lg border px-3 py-1.5 text-sm dark:border-slate-700" @click="editing = false; domain = website.domain">Cancel</button>
        </div>
        <dl v-else class="mt-4 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
            <dt class="text-slate-500">Domain</dt>
            <dd class="truncate">
                <a :href="visitUrl" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ website.domain }}</a>
            </dd>
            <dt class="text-slate-500">Points to</dt>
            <dd class="truncate">
                <Link v-if="parent" :href="panelRoute('websites.manage', { id: parent.id })" class="hover:underline">{{ parent.domain }}</Link>
                <span v-else>-</span>
            </dd>
            <dt class="text-slate-500">Status</dt>
            <dd>{{ website.status }}</dd>
        </dl>
        <p v-if="editing" class="mt-2 text-xs text-slate-500">Changing the domain removes its current certificate; issue SSL again afterwards.</p>

        <div v-if="!editing" class="mt-5 flex flex-wrap gap-2">
            <button type="button" :disabled="Boolean(busy)"
                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800"
                @click="editing = true">
                Change domain
            </button>
            <button type="button" :disabled="Boolean(busy)"
                class="rounded-lg border border-red-300 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 disabled:opacity-60 dark:border-red-900 dark:hover:bg-red-500/10"
                @click="removeAlias">
                {{ busy === 'remove' ? 'Removing…' : 'Remove alias' }}
            </button>
        </div>
    </section>
</template>
