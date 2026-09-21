<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';

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
        db.testResult.value = e.response?.data ?? { success: false, error: 'Request failed.' };
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
        redis.testResult.value = e.response?.data ?? { success: false, error: 'Request failed.' };
    } finally {
        redis.testing.value = false;
    }
};

const applyDatabase = async () => {
    if (!confirm('This will back up the current configuration, write the new database connection, and restart PHP-FPM and queue workers. If verification fails afterward, dpanel will automatically restore the previous configuration. Continue?')) return;
    db.applying.value = true;
    db.applyResult.value = null;
    try {
        const { data } = await axios.post(panelRoute('self-connection.database.apply'), {
            ...db.form.value,
            force_apply_without_schema: db.forceApplyWithoutSchema.value,
        });
        db.applyResult.value = data;
    } catch (e) {
        db.applyResult.value = e.response?.data ?? { success: false, error: 'Request failed.' };
    } finally {
        db.applying.value = false;
    }
};

const applyRedis = async () => {
    if (!confirm('This will back up the current configuration, write the new Redis connection, and restart PHP-FPM and queue workers. If verification fails afterward, dpanel will automatically restore the previous configuration. Continue?')) return;
    redis.applying.value = true;
    redis.applyResult.value = null;
    try {
        const { data } = await axios.post(panelRoute('self-connection.redis.apply'), redis.form.value);
        redis.applyResult.value = data;
    } catch (e) {
        redis.applyResult.value = e.response?.data ?? { success: false, error: 'Request failed.' };
    } finally {
        redis.applying.value = false;
    }
};
</script>

<template>
    <Head title="Database & Redis Connection" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Database &amp; Redis Connection</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Point dpanel's own database and Redis at a remote host. Always test before applying &mdash; failed changes are rolled back automatically.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">Remote Database</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Current: <code>{{ current.database.host }}:{{ current.database.port }}</code> / <code>{{ current.database.database }}</code>
                    </p>
                </div>

                <div v-if="db.applying.value" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    Applying &mdash; do not close this page. Restarting PHP-FPM and queue workers…
                </div>
                <div v-else-if="db.applyResult.value" :class="db.applyResult.value.success ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ db.applyResult.value.success ? 'Applied successfully.' : 'Apply failed.' }}</strong>
                    <span v-if="db.applyResult.value.error"> {{ db.applyResult.value.error }}</span>
                    <span v-if="db.applyResult.value.rolled_back"> The previous configuration was automatically restored.</span>
                    <div v-if="db.applyResult.value.backup" class="mt-1 text-xs">Backup saved at <code>{{ db.applyResult.value.backup }}</code></div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">Host</label>
                        <input v-model="db.form.value.host" type="text" placeholder="db.example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Port</label>
                        <input v-model.number="db.form.value.port" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Database</label>
                        <input v-model="db.form.value.database" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Username</label>
                        <input v-model="db.form.value.username" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Password</label>
                        <div class="flex items-center gap-2">
                            <input v-model="db.form.value.password" :type="db.showPassword.value ? 'text' : 'password'" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="db.showPassword.value = !db.showPassword.value">
                                {{ db.showPassword.value ? 'Hide' : 'Show' }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Driver</label>
                        <select v-model="db.form.value.driver" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="mysql">mysql</option>
                            <option value="mariadb">mariadb</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Charset</label>
                        <input v-model="db.form.value.charset" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Collation</label>
                        <input v-model="db.form.value.collation" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm">SSL CA Path (optional, must exist on this server)</label>
                        <input v-model="db.form.value.ssl_ca_path" type="text" placeholder="/etc/ssl/certs/rds-ca.pem" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                </div>

                <div v-if="db.testResult.value" :class="db.testResult.value.success ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ db.testResult.value.success ? 'Connection succeeded.' : 'Connection failed.' }}</strong>
                    <span v-if="db.testResult.value.error"> {{ db.testResult.value.error }}</span>
                </div>
                <div v-if="db.testResult.value?.success && !db.testResult.value.schema_ready" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    This database does not appear to contain dpanel's tables yet. Applying now will likely break the app until you migrate your data there first.
                    <label class="mt-2 flex items-center gap-2 text-xs">
                        <input v-model="db.forceApplyWithoutSchema.value" type="checkbox" />
                        I understand and have already migrated my data.
                    </label>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button type="button" :disabled="db.testing.value" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="testDatabase">
                        {{ db.testing.value ? 'Testing…' : 'Test Connection' }}
                    </button>
                    <button type="button" :disabled="!canApplyDb || db.applying.value" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="applyDatabase">
                        Save &amp; Apply
                    </button>
                    <span v-if="!dbTested" class="text-xs text-slate-500 dark:text-slate-400">Test the connection with these exact values before applying.</span>
                </div>

                <p v-if="meta.database.last_applied_at" class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    Last applied {{ meta.database.last_applied_at }} by {{ meta.database.last_applied_by || 'unknown' }} &mdash;
                    {{ meta.database.last_applied_success ? 'success' : 'failed' }}
                </p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">Remote Redis</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Current: <code>{{ current.redis.host }}:{{ current.redis.port }}</code> / DB {{ current.redis.database }}
                    </p>
                </div>

                <div v-if="redis.applying.value" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    Applying &mdash; do not close this page. Restarting PHP-FPM and queue workers…
                </div>
                <div v-else-if="redis.applyResult.value" :class="redis.applyResult.value.success ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ redis.applyResult.value.success ? 'Applied successfully.' : 'Apply failed.' }}</strong>
                    <span v-if="redis.applyResult.value.error"> {{ redis.applyResult.value.error }}</span>
                    <span v-if="redis.applyResult.value.rolled_back"> The previous configuration was automatically restored.</span>
                    <div v-if="redis.applyResult.value.backup" class="mt-1 text-xs">Backup saved at <code>{{ redis.applyResult.value.backup }}</code></div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">Host</label>
                        <input v-model="redis.form.value.host" type="text" placeholder="redis.example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Port</label>
                        <input v-model.number="redis.form.value.port" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Username (optional)</label>
                        <input v-model="redis.form.value.username" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Password</label>
                        <div class="flex items-center gap-2">
                            <input v-model="redis.form.value.password" :type="redis.showPassword.value ? 'text' : 'password'" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="redis.showPassword.value = !redis.showPassword.value">
                                {{ redis.showPassword.value ? 'Hide' : 'Show' }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Database Index</label>
                        <input v-model.number="redis.form.value.database" type="number" min="0" max="15" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div class="flex items-center gap-2">
                        <input v-model="redis.form.value.tls" type="checkbox" id="redis_tls" />
                        <label for="redis_tls" class="text-sm">Use TLS</label>
                    </div>
                </div>

                <div v-if="redis.testResult.value" :class="redis.testResult.value.success ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ redis.testResult.value.success ? 'Connection succeeded.' : 'Connection failed.' }}</strong>
                    <span v-if="redis.testResult.value.error"> {{ redis.testResult.value.error }}</span>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button type="button" :disabled="redis.testing.value" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="testRedis">
                        {{ redis.testing.value ? 'Testing…' : 'Test Connection' }}
                    </button>
                    <button type="button" :disabled="!canApplyRedis || redis.applying.value" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="applyRedis">
                        Save &amp; Apply
                    </button>
                    <span v-if="!redisTested" class="text-xs text-slate-500 dark:text-slate-400">Test the connection with these exact values before applying.</span>
                </div>

                <p v-if="meta.redis.last_applied_at" class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    Last applied {{ meta.redis.last_applied_at }} by {{ meta.redis.last_applied_by || 'unknown' }} &mdash;
                    {{ meta.redis.last_applied_success ? 'success' : 'failed' }}
                </p>
            </section>

            <section v-if="meta.audit_log.length" class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Audit Log</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">Time</th>
                                <th class="px-3 py-2">User</th>
                                <th class="px-3 py-2">Target</th>
                                <th class="px-3 py-2">Action</th>
                                <th class="px-3 py-2">Host</th>
                                <th class="px-3 py-2">Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(entry, index) in meta.audit_log.slice().reverse()" :key="index" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2 text-xs">{{ entry.at }}</td>
                                <td class="px-3 py-2 text-xs">{{ entry.by_email || 'unknown' }}</td>
                                <td class="px-3 py-2 text-xs">{{ entry.target }}</td>
                                <td class="px-3 py-2 text-xs">{{ entry.action }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ entry.masked_host || '—' }}</td>
                                <td class="px-3 py-2 text-xs" :class="entry.success ? 'text-emerald-600' : 'text-red-600'">{{ entry.success ? 'success' : 'failed' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
