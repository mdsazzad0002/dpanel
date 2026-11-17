<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    current: { type: Object, required: true },
    meta: { type: Object, required: true },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const makeSection = (initial) => ({
    form: ref({ ...initial }),
    showPassword: ref(false),
    testing: ref(false),
    applying: ref(false),
    testResult: ref(null),
    testedSignature: ref(''),
    forceApplyWithoutSchema: ref(false),
    confirmOpen: ref(false),
    applyResult: ref(null),
});

const db = makeSection({
    host: '', port: 3306, database: '', username: '', password: '',
    charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci', driver: 'mysql', ssl_ca_path: '',
});
const redis = makeSection({
    host: '', port: 6379, username: '', password: '', database: 0, tls: false,
});

const signature = (form) => JSON.stringify(form);

const dbTested = computed(() => db.testResult.value?.success && db.testedSignature.value === signature(db.form.value));
const redisTested = computed(() => redis.testResult.value?.success && redis.testedSignature.value === signature(redis.form.value));

const canApplyDb = computed(() => dbTested.value && (db.testResult.value?.schema_ready || db.forceApplyWithoutSchema.value));
const canApplyRedis = computed(() => redisTested.value);

const testDatabase = async () => {
    db.testing.value = true;
    db.testResult.value = null;
    try {
        const { data } = await axios.post(panelRoute('self-connection.database.test'), db.form.value);
        db.testResult.value = data;
        db.testedSignature.value = signature(db.form.value);
    } catch (e) {
        db.testResult.value = e.response?.data ?? { success: false, error: t('settings.selfConnection.requestFailed') };
    } finally {
        db.testing.value = false;
    }
};

const testRedis = async () => {
    redis.testing.value = true;
    redis.testResult.value = null;
    try {
        const { data } = await axios.post(panelRoute('self-connection.redis.test'), redis.form.value);
        redis.testResult.value = data;
        redis.testedSignature.value = signature(redis.form.value);
    } catch (e) {
        redis.testResult.value = e.response?.data ?? { success: false, error: t('settings.selfConnection.requestFailed') };
    } finally {
        redis.testing.value = false;
    }
};

const applyDatabase = async () => {
    if (!confirm(t('settings.selfConnection.applyDbConfirm'))) return;
    db.applying.value = true;
    db.applyResult.value = null;
    try {
        const { data } = await axios.post(panelRoute('self-connection.database.apply'), {
            ...db.form.value,
            force_apply_without_schema: db.forceApplyWithoutSchema.value,
        });
        db.applyResult.value = data;
    } catch (e) {
        db.applyResult.value = e.response?.data ?? { success: false, error: t('settings.selfConnection.requestFailed') };
    } finally {
        db.applying.value = false;
    }
};

const applyRedis = async () => {
    if (!confirm(t('settings.selfConnection.applyRedisConfirm'))) return;
    redis.applying.value = true;
    redis.applyResult.value = null;
    try {
        const { data } = await axios.post(panelRoute('self-connection.redis.apply'), redis.form.value);
        redis.applyResult.value = data;
    } catch (e) {
        redis.applyResult.value = e.response?.data ?? { success: false, error: t('settings.selfConnection.requestFailed') };
    } finally {
        redis.applying.value = false;
    }
};
</script>

<template>
    <Head :title="t('settings.selfConnection.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('settings.selfConnection.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('settings.selfConnection.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">{{ t('settings.selfConnection.dbTitle') }}</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        <i18n-t keypath="settings.selfConnection.currentDb" scope="global"><template #host><code>{{ current.database.host }}:{{ current.database.port }}</code></template><template #database><code>{{ current.database.database }}</code></template></i18n-t>
                    </p>
                </div>

                <div v-if="db.applying.value" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    {{ t('settings.selfConnection.applying') }}
                </div>
                <div v-else-if="db.applyResult.value" :class="db.applyResult.value.success ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ db.applyResult.value.success ? t('settings.selfConnection.applied') : t('settings.selfConnection.applyFailed') }}</strong>
                    <span v-if="db.applyResult.value.error"> {{ db.applyResult.value.error }}</span>
                    <span v-if="db.applyResult.value.rolled_back"> {{ t('settings.selfConnection.rolledBack') }}</span>
                    <div v-if="db.applyResult.value.backup" class="mt-1 text-xs"><i18n-t keypath="settings.selfConnection.backupSaved" scope="global"><template #path><code>{{ db.applyResult.value.backup }}</code></template></i18n-t></div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.host') }}</label>
                        <input v-model="db.form.value.host" type="text" placeholder="db.example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.port') }}</label>
                        <input v-model.number="db.form.value.port" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.database') }}</label>
                        <input v-model="db.form.value.database" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.username') }}</label>
                        <input v-model="db.form.value.username" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.password') }}</label>
                        <div class="flex items-center gap-2">
                            <input v-model="db.form.value.password" :type="db.showPassword.value ? 'text' : 'password'" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="db.showPassword.value = !db.showPassword.value">
                                {{ db.showPassword.value ? t('settings.selfConnection.hide') : t('settings.selfConnection.show') }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.driver') }}</label>
                        <select v-model="db.form.value.driver" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="mysql">mysql</option>
                            <option value="mariadb">mariadb</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.charset') }}</label>
                        <input v-model="db.form.value.charset" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.collation') }}</label>
                        <input v-model="db.form.value.collation" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.sslCa') }}</label>
                        <input v-model="db.form.value.ssl_ca_path" type="text" placeholder="/etc/ssl/certs/rds-ca.pem" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                </div>

                <div v-if="db.testResult.value" :class="db.testResult.value.success ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ db.testResult.value.success ? t('settings.selfConnection.connOk') : t('settings.selfConnection.connFailed') }}</strong>
                    <span v-if="db.testResult.value.error"> {{ db.testResult.value.error }}</span>
                </div>
                <div v-if="db.testResult.value?.success && !db.testResult.value.schema_ready" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    {{ t('settings.selfConnection.noSchema') }}
                    <label class="mt-2 flex items-center gap-2 text-xs">
                        <input v-model="db.forceApplyWithoutSchema.value" type="checkbox" />
                        {{ t('settings.selfConnection.forceApply') }}
                    </label>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button type="button" :disabled="db.testing.value" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="testDatabase">
                        {{ db.testing.value ? t('settings.selfConnection.testing') : t('settings.selfConnection.test') }}
                    </button>
                    <button type="button" :disabled="!canApplyDb || db.applying.value" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="applyDatabase">
                        {{ t('settings.selfConnection.saveApply') }}
                    </button>
                    <span v-if="!dbTested" class="text-xs text-slate-500 dark:text-slate-400">{{ t('settings.selfConnection.testFirst') }}</span>
                </div>

                <p v-if="meta.database.last_applied_at" class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    {{ t('settings.selfConnection.lastApplied', { at: meta.database.last_applied_at, by: meta.database.last_applied_by || t('settings.selfConnection.unknown'), result: meta.database.last_applied_success ? t('settings.selfConnection.success') : t('settings.selfConnection.failed') }) }}
                </p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">{{ t('settings.selfConnection.redisTitle') }}</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        <i18n-t keypath="settings.selfConnection.currentRedis" scope="global"><template #host><code>{{ current.redis.host }}:{{ current.redis.port }}</code></template><template #db>{{ current.redis.database }}</template></i18n-t>
                    </p>
                </div>

                <div v-if="redis.applying.value" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    {{ t('settings.selfConnection.applying') }}
                </div>
                <div v-else-if="redis.applyResult.value" :class="redis.applyResult.value.success ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ redis.applyResult.value.success ? t('settings.selfConnection.applied') : t('settings.selfConnection.applyFailed') }}</strong>
                    <span v-if="redis.applyResult.value.error"> {{ redis.applyResult.value.error }}</span>
                    <span v-if="redis.applyResult.value.rolled_back"> {{ t('settings.selfConnection.rolledBack') }}</span>
                    <div v-if="redis.applyResult.value.backup" class="mt-1 text-xs"><i18n-t keypath="settings.selfConnection.backupSaved" scope="global"><template #path><code>{{ redis.applyResult.value.backup }}</code></template></i18n-t></div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.host') }}</label>
                        <input v-model="redis.form.value.host" type="text" placeholder="redis.example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.port') }}</label>
                        <input v-model.number="redis.form.value.port" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.usernameOptional') }}</label>
                        <input v-model="redis.form.value.username" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.password') }}</label>
                        <div class="flex items-center gap-2">
                            <input v-model="redis.form.value.password" :type="redis.showPassword.value ? 'text' : 'password'" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="redis.showPassword.value = !redis.showPassword.value">
                                {{ redis.showPassword.value ? t('settings.selfConnection.hide') : t('settings.selfConnection.show') }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('settings.selfConnection.dbIndex') }}</label>
                        <input v-model.number="redis.form.value.database" type="number" min="0" max="15" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div class="flex items-center gap-2">
                        <input v-model="redis.form.value.tls" type="checkbox" id="redis_tls" />
                        <label for="redis_tls" class="text-sm">{{ t('settings.selfConnection.tls') }}</label>
                    </div>
                </div>

                <div v-if="redis.testResult.value" :class="redis.testResult.value.success ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ redis.testResult.value.success ? t('settings.selfConnection.connOk') : t('settings.selfConnection.connFailed') }}</strong>
                    <span v-if="redis.testResult.value.error"> {{ redis.testResult.value.error }}</span>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button type="button" :disabled="redis.testing.value" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="testRedis">
                        {{ redis.testing.value ? t('settings.selfConnection.testing') : t('settings.selfConnection.test') }}
                    </button>
                    <button type="button" :disabled="!canApplyRedis || redis.applying.value" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="applyRedis">
                        {{ t('settings.selfConnection.saveApply') }}
                    </button>
                    <span v-if="!redisTested" class="text-xs text-slate-500 dark:text-slate-400">{{ t('settings.selfConnection.testFirst') }}</span>
                </div>

                <p v-if="meta.redis.last_applied_at" class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    {{ t('settings.selfConnection.lastApplied', { at: meta.redis.last_applied_at, by: meta.redis.last_applied_by || t('settings.selfConnection.unknown'), result: meta.redis.last_applied_success ? t('settings.selfConnection.success') : t('settings.selfConnection.failed') }) }}
                </p>
            </section>

            <section v-if="meta.audit_log.length" class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ t('settings.selfConnection.audit') }}</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">{{ t('settings.selfConnection.cols.time') }}</th>
                                <th class="px-3 py-2">{{ t('settings.selfConnection.cols.user') }}</th>
                                <th class="px-3 py-2">{{ t('settings.selfConnection.cols.target') }}</th>
                                <th class="px-3 py-2">{{ t('settings.selfConnection.cols.action') }}</th>
                                <th class="px-3 py-2">{{ t('settings.selfConnection.host') }}</th>
                                <th class="px-3 py-2">{{ t('settings.selfConnection.cols.result') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(entry, index) in meta.audit_log.slice().reverse()" :key="index" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2 text-xs">{{ entry.at }}</td>
                                <td class="px-3 py-2 text-xs">{{ entry.by_email || t('settings.selfConnection.unknown') }}</td>
                                <td class="px-3 py-2 text-xs">{{ entry.target }}</td>
                                <td class="px-3 py-2 text-xs">{{ entry.action }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ entry.masked_host || '—' }}</td>
                                <td class="px-3 py-2 text-xs" :class="entry.success ? 'text-emerald-600' : 'text-red-600'">{{ entry.success ? t('settings.selfConnection.success') : t('settings.selfConnection.failed') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
