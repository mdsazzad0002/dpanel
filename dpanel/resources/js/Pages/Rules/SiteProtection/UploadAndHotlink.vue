<script setup>
// Task S6: folders where PHP never runs, hotlink protection for media, Cloudflare only.
import InputError from '@/Components/InputError.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    website: { type: Object, required: true },
});

const { t } = useI18n();
const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const lines = (list) => list.join('\n');
const split = (text) => text.split(/[\n,]+/).map((v) => v.trim()).filter(Boolean);

const form = useForm({
    no_php_paths: lines(props.website.no_php_paths),
    denied_paths: lines(props.website.denied_paths ?? []),
    denied_extensions: lines(props.website.denied_extensions ?? []),
    hotlink_protection: props.website.hotlink_protection,
    hotlink_allowed_hosts: lines(props.website.hotlink_allowed_hosts),
    cloudflare_only: props.website.cloudflare_only,
});

const fieldError = (name) => computed(() => Object.entries(form.errors).find(([key]) => key === name || key.startsWith(`${name}.`))?.[1]);
const pathsError = fieldError('no_php_paths');
const deniedError = fieldError('denied_paths');
const typesError = fieldError('denied_extensions');
const hostsError = fieldError('hotlink_allowed_hosts');

const addWordpressUploads = () => {
    const paths = split(form.no_php_paths);
    if (!paths.includes('/wp-content/uploads')) paths.push('/wp-content/uploads');
    form.no_php_paths = lines(paths);
};

const save = () => {
    form.transform((data) => ({
        no_php_paths: split(data.no_php_paths),
        denied_paths: split(data.denied_paths),
        denied_extensions: split(data.denied_extensions),
        hotlink_protection: data.hotlink_protection,
        hotlink_allowed_hosts: split(data.hotlink_allowed_hosts),
        cloudflare_only: data.cloudflare_only,
    })).put(panelRoute('rules.site-protection.settings.update', { website: props.website.id }), { preserveScroll: true });
};
</script>

<template>
    <section class="rounded-lg border border-slate-200 p-5 dark:border-slate-700">
        <form class="space-y-5" @submit.prevent="save">
            <div>
                <h2 class="text-lg font-semibold">No PHP in these folders</h2>
                <p class="mt-1 text-sm text-slate-500">A PHP file under these folders is refused with 403, so an uploaded script cannot run. One folder per line.</p>
                <textarea v-model="form.no_php_paths" rows="3" placeholder="/wp-content/uploads" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                <button type="button" class="mt-1 text-xs text-blue-600 hover:underline dark:text-blue-400" @click="addWordpressUploads">Add the WordPress uploads folder</button>
                <InputError :message="pathsError" class="mt-1" />
            </div>

            <div>
                <h2 class="text-lg font-semibold">{{ t('siteProtection.blockedFolders') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ t('siteProtection.blockedFoldersHelp') }}</p>
                <textarea v-model="form.denied_paths" rows="3" placeholder="/var" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                <InputError :message="deniedError" class="mt-1" />
            </div>

            <div>
                <h2 class="text-lg font-semibold">{{ t('siteProtection.blockedTypes') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ t('siteProtection.blockedTypesHelp') }}</p>
                <textarea v-model="form.denied_extensions" rows="3" placeholder="log&#10;/modules/*.tpl" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                <p class="mt-1 text-xs text-slate-500">{{ t('siteProtection.hiddenFilesNote') }}</p>
                <InputError :message="typesError" class="mt-1" />
            </div>

            <div>
                <h2 class="text-lg font-semibold">Hotlink protection</h2>
                <label class="mt-2 flex items-center gap-2 text-sm">
                    <input v-model="form.hotlink_protection" type="checkbox" class="rounded border-slate-300 text-blue-600 dark:border-slate-600" />
                    Refuse images, video, audio and PDFs embedded by other sites
                </label>
                <p class="mt-1 text-xs text-slate-500">Requests without a referrer (direct visits, many apps) are always allowed.</p>
                <div v-if="form.hotlink_protection" class="mt-3">
                    <label class="mb-1 block text-sm">Other sites that may embed them (optional, one per line)</label>
                    <textarea v-model="form.hotlink_allowed_hosts" rows="2" placeholder="partner.example" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                    <p class="mt-1 text-xs text-slate-500">{{ website.domain }} and its aliases are always allowed, with any subdomain of a listed host.</p>
                    <InputError :message="hostsError" class="mt-1" />
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold">Cloudflare only</h2>
                <label class="mt-2 flex items-center gap-2 text-sm">
                    <input v-model="form.cloudflare_only" type="checkbox" class="rounded border-slate-300 text-blue-600 dark:border-slate-600" />
                    Accept requests only through Cloudflare's proxy
                </label>
                <p class="mt-1 text-xs text-slate-500">Visitors who reach this server directly get a 403, so nobody can get around Cloudflare. Turn it on only when the domain's DNS record is proxied (orange cloud), or the site stops working.</p>
            </div>

            <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                Save
            </button>
        </form>
    </section>
</template>
