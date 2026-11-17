<script setup>
import { ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { isClosedRoute } from '@/navigation/panelMenu';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    websiteDomains: {
        type: Array,
        default: () => [],
    },
    databasePrefixes: {
        type: Object,
        default: () => ({}),
    },
    postgresql: {
        type: Object,
        default: () => ({ installed: false, active: false }),
    },
    mongodb: {
        type: Object,
        default: () => ({ installed: false, active: false }),
    },
});

const prefillDomain = new URLSearchParams(window.location.search).get('domain') || '';

const form = useForm({
    engine: 'mariadb',
    domain: props.websiteDomains.includes(prefillDomain) ? prefillDomain : '',
    database_name: '',
    database_user: '',
    database_password: '',
    database_host: '127.0.0.1',
    charset: 'utf8mb4',
    collation: 'utf8mb4_unicode_ci',
});
const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const showPassword = ref(false);
const isPostgresql = computed(() => form.engine === 'postgresql');
const isMongodb = computed(() => form.engine === 'mongodb');
// PostgreSQL and MongoDB databases are always created on this server.
const isLocalOnly = computed(() => isPostgresql.value || isMongodb.value);
const localHost = computed(() => (isMongodb.value ? '127.0.0.1:27017' : '127.0.0.1:5432'));
const engineOptions = computed(() => [
    { value: 'mariadb', label: 'MariaDB / MySQL', hint: t('databases.create.opensIn', { tool: 'phpMyAdmin' }), disabled: false },
    {
        value: 'postgresql',
        label: 'PostgreSQL',
        hint: !props.postgresql.installed ? t('databases.create.notInstalled') : t('databases.create.opensIn', { tool: 'pgAdmin' }),
        disabled: !props.postgresql.installed,
    },
    {
        value: 'mongodb',
        label: 'MongoDB',
        hint: !props.mongodb.installed ? t('databases.create.notInstalled') : t('databases.create.opensIn', { tool: 'Mongo Admin' }),
        disabled: !props.mongodb.installed,
    },
].filter((option) => !isClosedRoute(`databases.${option.value}`, page.props.platform?.closedRoutes ?? [])));
const useRemoteHost = ref(false);
const suggestedDatabaseName = ref('');
const suggestedDatabaseUser = ref('');
const selectedOwnerPrefix = computed(() => String(props.databasePrefixes[String(form.domain).trim().toLowerCase()] || ''));
const selectedDomainPart = computed(() => String(form.domain).trim().toLowerCase().split('.')[0].replace(/[^a-z0-9_]/g, '_').replace(/^_+|_+$/g, '').slice(0, 16) || 'site');
const websiteDomainOptions = computed(() => props.websiteDomains.map((domain) => ({ value: domain, label: domain })));
const databaseUserSuggestions = computed(() => [
    `${selectedDomainPart.value}_user`,
    `${selectedDomainPart.value}_usr`,
    `${selectedDomainPart.value}_admin`,
]);

const submit = () => {
    form.post(panelRoute('databases.store'));
};

watch(
    () => form.engine,
    (engine) => {
        if (engine !== 'mariadb') {
            // PostgreSQL and MongoDB databases are always local and UTF-8.
            useRemoteHost.value = false;
            form.database_host = '127.0.0.1';
        }
    },
);

const toggleRemoteHost = () => {
    useRemoteHost.value = !useRemoteHost.value;
    if (!useRemoteHost.value) {
        form.database_host = '127.0.0.1';
    }
};

const generatePassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
    const bytes = new Uint32Array(16);
    window.crypto.getRandomValues(bytes);
    form.database_password = Array.from(bytes, (value) => chars[value % chars.length]).join('');
    showPassword.value = true;
};

const sanitizePrefix = (domain) => {
    const accountPrefix = String(props.databasePrefixes[String(domain).trim().toLowerCase()] || '');
    if (accountPrefix) return accountPrefix.slice(0, 48);

    const root = String(domain).trim().toLowerCase().split('.')[0] || '';
    return root.replace(/[^a-z0-9_]/g, '_').slice(0, 48);
};

watch(
    () => form.domain,
    (domain) => {
        const prefix = sanitizePrefix(domain);
        if (!prefix) return;
        const domainPart = String(domain).trim().toLowerCase().split('.')[0].replace(/[^a-z0-9_]/g, '_').replace(/^_+|_+$/g, '').slice(0, 16) || 'site';

        const nextDatabaseName = `${domainPart}_db`;
        const nextDatabaseUser = `${domainPart}_user`;

        if (!form.database_name || form.database_name === suggestedDatabaseName.value) {
            form.database_name = nextDatabaseName;
        }
        if (!form.database_user || form.database_user === suggestedDatabaseUser.value) {
            form.database_user = nextDatabaseUser;
        }

        suggestedDatabaseName.value = nextDatabaseName;
        suggestedDatabaseUser.value = nextDatabaseUser;
    },
);
</script>

<template>
    <Head :title="t('databases.create.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('databases.create.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('databases.create.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="panelRoute('databases.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    {{ t('databases.create.list') }}
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <fieldset class="md:col-span-2">
                    <legend class="mb-1 block text-sm">{{ t('databases.create.engine') }}</legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label
                            v-for="option in engineOptions"
                            :key="option.value"
                            :class="[
                                form.engine === option.value
                                    ? 'border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-950/30'
                                    : 'border-slate-300 dark:border-slate-700',
                                option.disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-blue-400',
                            ]"
                            class="flex items-start gap-3 rounded-md border px-3 py-2"
                        >
                            <input v-model="form.engine" type="radio" name="engine" :value="option.value" :disabled="option.disabled" class="mt-1" />
                            <span>
                                <span class="block text-sm font-medium">{{ option.label }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ option.hint }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="isPostgresql && !postgresql.active" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        {{ t('databases.create.engineOff', { engine: 'PostgreSQL' }) }}
                    </p>
                    <p v-if="isMongodb && !mongodb.active" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        {{ t('databases.create.engineOff', { engine: 'MongoDB' }) }}
                    </p>
                    <p v-if="form.errors.engine" class="mt-1 text-xs text-red-600">{{ form.errors.engine }}</p>
                </fieldset>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">{{ t('databases.form.websiteDomain') }}</label>
                    <SearchableSelect
                        v-model="form.domain"
                        :options="websiteDomainOptions"
                        :placeholder="t('databases.form.selectDomain')"
                        :search-placeholder="t('databases.create.searchDomains')"
                    />
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                    <p v-if="websiteDomains.length === 0" class="mt-1 text-xs text-amber-600">
                        {{ t('databases.create.noDomains') }}
                    </p>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">{{ t('databases.form.manualDomain') }}</label>
                    <input v-model="form.domain" type="text" placeholder="example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('databases.form.name') }}</label>
                    <div class="flex rounded-md border border-slate-300 bg-white focus-within:border-blue-500 dark:border-slate-700 dark:bg-slate-800"><span class="flex items-center border-r border-slate-300 bg-slate-50 px-3 font-mono text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">{{ selectedOwnerPrefix ? `${selectedOwnerPrefix}_` : 'owner_' }}</span><input v-model="form.database_name" maxlength="31" required type="text" placeholder="domain_db" class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-sm focus:ring-0" /></div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('databases.create.prefixHelp') }}</p>
                    <p v-if="form.errors.database_name" class="mt-1 text-xs text-red-600">{{ form.errors.database_name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('databases.form.user') }}</label>
                    <div class="flex rounded-md border border-slate-300 bg-white focus-within:border-blue-500 dark:border-slate-700 dark:bg-slate-800"><span class="flex items-center border-r border-slate-300 bg-slate-50 px-3 font-mono text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">{{ selectedOwnerPrefix ? `${selectedOwnerPrefix}_` : 'owner_' }}</span><input v-model="form.database_user" maxlength="31" required type="text" placeholder="domain_user" class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-sm focus:ring-0" /></div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('databases.create.prefixHelp') }}</p>
                    <div v-if="form.domain" class="mt-2 flex flex-wrap items-center gap-1.5 text-xs"><span class="text-slate-500 dark:text-slate-400">{{ t('databases.create.suggestions') }}</span><button v-for="suggestion in databaseUserSuggestions" :key="suggestion" type="button" class="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 font-mono text-slate-600 hover:border-blue-300 hover:text-blue-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300" @click="form.database_user = suggestion">{{ suggestion }}</button></div>
                    <p v-if="form.errors.database_user" class="mt-1 text-xs text-red-600">{{ form.errors.database_user }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('databases.form.password') }}</label>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="form.database_password"
                            :type="showPassword ? 'text' : 'password'"
                            :placeholder="t('databases.form.autoGenerated')"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        />
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                            @click="showPassword = !showPassword"
                        >
                            {{ showPassword ? t('databases.form.hide') : t('databases.form.show') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-blue-300 px-3 py-2 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-400 dark:hover:bg-blue-900/20"
                            @click="generatePassword"
                        >
                            {{ t('databases.form.generate') }}
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('databases.form.passwordHelp') }}</p>
                    <p v-if="form.errors.database_password" class="mt-1 text-xs text-red-600">{{ form.errors.database_password }}</p>
                </div>
                <div v-if="!isLocalOnly">
                    <div class="mb-1 flex items-center justify-between">
                        <label class="block text-sm">{{ t('databases.form.host') }}</label>
                        <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                            <input type="checkbox" :checked="useRemoteHost" @change="toggleRemoteHost" />
                            {{ t('databases.form.useRemote') }}
                        </label>
                    </div>
                    <input
                        v-model="form.database_host"
                        type="text"
                        :disabled="!useRemoteHost"
                        :placeholder="t('databases.form.hostPlaceholder')"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:disabled:bg-slate-900 dark:disabled:text-slate-500"
                    />
                    <p v-if="useRemoteHost" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        <i18n-t keypath="databases.form.remoteHelp" scope="global"><template #env>dPanel_DATABASE_API_URL</template></i18n-t>
                    </p>
                    <p v-if="form.errors.database_host" class="mt-1 text-xs text-red-600">{{ form.errors.database_host }}</p>
                </div>
                <div v-if="isLocalOnly">
                    <label class="mb-1 block text-sm">{{ t('databases.form.host') }}</label>
                    <input :value="localHost" type="text" disabled class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:disabled:bg-slate-900" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ isMongodb ? t('databases.create.mongoLocal') : t('databases.create.pgLocal') }}</p>
                </div>
                <div v-if="!isLocalOnly">
                    <label class="mb-1 block text-sm">{{ t('databases.form.charset') }}</label>
                    <select v-model="form.charset" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="utf8mb4">utf8mb4</option>
                        <option value="utf8">utf8</option>
                        <option value="latin1">latin1</option>
                    </select>
                    <p v-if="form.errors.charset" class="mt-1 text-xs text-red-600">{{ form.errors.charset }}</p>
                </div>
                <div v-if="!isLocalOnly">
                    <label class="mb-1 block text-sm">{{ t('databases.form.collation') }}</label>
                    <select v-model="form.collation" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>
                        <option value="utf8mb4_general_ci">utf8mb4_general_ci</option>
                        <option value="utf8_general_ci">utf8_general_ci</option>
                        <option value="latin1_swedish_ci">latin1_swedish_ci</option>
                    </select>
                    <p v-if="form.errors.collation" class="mt-1 text-xs text-red-600">{{ form.errors.collation }}</p>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ t('databases.create.title') }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
