<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    server: { type: Object, required: true },
    connection: { type: Object, default: () => ({}) },
    // A diagnosis cause from the connection test; its section is highlighted.
    cause: { type: String, default: '' },
    // Compact: only the section for the cause (used next to a failed test).
    compact: { type: Boolean, default: false },
});

const range = computed(() => `${props.server.pasv_min}-${props.server.pasv_max}`);
const showRouter = computed(() => props.cause === 'nat_forward' || (!props.compact && props.server.behind_nat));
const showFirewall = computed(() => props.cause === 'firewall' || (!props.compact && !props.server.behind_nat));
const showHairpin = computed(() => props.cause === 'no_hairpin');
const showPublicIp = computed(() => props.cause === 'no_public_ip');
const highlight = (cause) => (props.cause === cause ? 'ring-2 ring-amber-400 dark:ring-amber-500' : '');
const routerRules = computed(() => [
    { name: t('ftp.guide.control'), external: String(props.connection.port || 21), internal: String(props.connection.port || 21) },
    { name: t('ftp.guide.passiveData'), external: range.value, internal: range.value },
]);
</script>

<template>
    <div class="space-y-4 text-sm">
        <template v-if="!compact">
            <div>
                <h3 class="font-semibold">{{ t('ftp.guide.howTitle') }}</h3>
                <p class="mt-1 text-slate-600 dark:text-slate-400">
                    {{ t('ftp.guide.how', { port: connection.port || 21, range }) }}
                </p>
            </div>
            <dl class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-950 sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs uppercase text-slate-500">{{ t('ftp.guide.publicIp') }}</dt><dd class="mt-1 font-mono">{{ server.public_ip || t('ftp.guide.unknown') }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">{{ t('ftp.guide.ownIp') }}</dt><dd class="mt-1 font-mono">{{ server.local_ip || t('ftp.guide.unknown') }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">{{ t('ftp.guide.passivePorts') }}</dt><dd class="mt-1 font-mono">TCP {{ range }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">{{ t('ftp.guide.network') }}</dt><dd class="mt-1">{{ server.behind_nat ? t('ftp.guide.nat') : t('ftp.guide.direct') }}</dd></div>
            </dl>
        </template>

        <section v-if="showRouter" class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" :class="highlight('nat_forward')">
            <h3 class="font-semibold"><i class="bi bi-router"></i> {{ t('ftp.guide.routerTitle') }}</h3>
            <p class="mt-1 text-slate-600 dark:text-slate-400">
                {{ t('ftp.guide.router', { ip: server.local_ip }) }}
            </p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[480px] text-left">
                    <thead class="text-xs uppercase text-slate-500"><tr><th class="py-1.5 pr-4">{{ t('ftp.guide.th.name') }}</th><th class="py-1.5 pr-4">{{ t('ftp.guide.th.protocol') }}</th><th class="py-1.5 pr-4">{{ t('ftp.guide.th.external') }}</th><th class="py-1.5 pr-4">{{ t('ftp.guide.th.internalIp') }}</th><th class="py-1.5">{{ t('ftp.guide.th.internal') }}</th></tr></thead>
                    <tbody class="font-mono">
                        <tr v-for="rule in routerRules" :key="rule.name" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-1.5 pr-4 font-sans">{{ rule.name }}</td><td class="py-1.5 pr-4">TCP</td><td class="py-1.5 pr-4">{{ rule.external }}</td><td class="py-1.5 pr-4">{{ server.local_ip }}</td><td class="py-1.5">{{ rule.internal }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-slate-600 dark:text-slate-400">
                <li>{{ t('ftp.guide.fixedIp', { ip: server.local_ip }) }}</li>
                <li><i18n-t keypath="ftp.guide.shrink" scope="global"><template #cmd><code class="rounded bg-slate-100 px-1 dark:bg-slate-800">DPANEL_FTP_PASV_MIN_PORT=30000 DPANEL_FTP_PASV_MAX_PORT=30010 dpanel module ftp update</code></template></i18n-t></li>
                <li><i18n-t keypath="ftp.guide.ipChange" scope="global"><template #cmd><code class="rounded bg-slate-100 px-1 dark:bg-slate-800">dpanel module ftp update</code></template></i18n-t></li>
            </ul>
        </section>

        <section v-if="showFirewall" class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" :class="highlight('firewall')">
            <h3 class="font-semibold"><i class="bi bi-shield-check"></i> {{ t('ftp.guide.fwTitle') }}</h3>
            <p class="mt-1 text-slate-600 dark:text-slate-400">
                <i18n-t keypath="ftp.guide.fw" scope="global"><template #port>{{ connection.port || 21 }}</template><template #range>{{ range }}</template><template #cmd><code class="rounded bg-slate-100 px-1 dark:bg-slate-800">dpanel module ftp update</code></template></i18n-t>
            </p>
        </section>

        <section v-if="showHairpin" class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" :class="highlight('no_hairpin')">
            <h3 class="font-semibold"><i class="bi bi-arrow-repeat"></i> {{ t('ftp.guide.hairpinTitle') }}</h3>
            <p class="mt-1 text-slate-600 dark:text-slate-400">
                {{ t('ftp.guide.hairpin', { ip: server.public_ip }) }}
            </p>
        </section>

        <section v-if="showPublicIp" class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" :class="highlight('no_public_ip')">
            <h3 class="font-semibold"><i class="bi bi-globe2"></i> {{ t('ftp.guide.pubTitle') }}</h3>
            <p class="mt-1 text-slate-600 dark:text-slate-400">
                <i18n-t keypath="ftp.guide.pub" scope="global"><template #cmd><code class="rounded bg-slate-100 px-1 dark:bg-slate-800">DPANEL_FTP_PASV_ADDRESS=&lt;public IP&gt; dpanel module ftp update</code></template></i18n-t>
            </p>
        </section>
    </div>
</template>
