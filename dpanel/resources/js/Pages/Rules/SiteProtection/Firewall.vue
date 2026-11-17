<script setup>
// Task S2: the web application firewall (OWASP CRS) for one website.
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    website: { type: Object, required: true },
    available: { type: Boolean, default: false },
});

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const form = useForm({ waf_mode: props.website.waf_mode ?? 'detect' });
const modes = computed(() => [
    { value: 'off', label: t('rules.siteProtection.firewall.off'), help: t('rules.siteProtection.firewall.offHelp') },
    { value: 'detect', label: t('rules.siteProtection.firewall.detect'), help: t('rules.siteProtection.firewall.detectHelp') },
    { value: 'block', label: t('rules.siteProtection.firewall.block'), help: t('rules.siteProtection.firewall.blockHelp') },
]);

const save = () => form.put(panelRoute('rules.site-protection.waf.update', { website: props.website.id }), { preserveScroll: true });
</script>

<template>
    <section class="rounded-lg border border-slate-200 p-5 dark:border-slate-700">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="text-lg font-semibold">{{ t('rules.siteProtection.firewall.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ t('rules.siteProtection.firewall.help') }}</p>
            </div>
            <Link :href="panelRoute('websites.logs.index', { id: website.id })" class="text-sm text-blue-600 hover:underline dark:text-blue-400">{{ t('rules.siteProtection.firewall.log') }}</Link>
        </div>

        <p v-if="!available" class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
            <i18n-t keypath="rules.siteProtection.firewall.notInstalled" scope="global"><template #cmd><code>dpanel module waf install</code></template></i18n-t>
        </p>

        <form class="mt-4 space-y-3" @submit.prevent="save">
            <label v-for="mode in modes" :key="mode.value" class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2 text-sm"
                :class="form.waf_mode === mode.value ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-slate-800' : 'border-slate-200 dark:border-slate-700'">
                <input v-model="form.waf_mode" type="radio" name="waf_mode" :value="mode.value" class="mt-0.5" />
                <span>
                    <span class="font-medium">{{ mode.label }}</span>
                    <span class="block text-slate-500">{{ mode.help }}</span>
                </span>
            </label>
            <p v-if="form.errors.waf_mode" class="text-xs text-red-600">{{ form.errors.waf_mode }}</p>
            <button type="submit" :disabled="form.processing || form.waf_mode === website.waf_mode" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">{{ t('common.save') }}</button>
        </form>
    </section>
</template>
