<script setup>
import { computed } from 'vue';

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
    { name: 'FTP control', external: String(props.connection.port || 21), internal: String(props.connection.port || 21) },
    { name: 'FTP passive data', external: range.value, internal: range.value },
]);
</script>

<template>
    <div class="space-y-4 text-sm">
        <template v-if="!compact">
            <div>
                <h3 class="font-semibold">How an FTP connection works</h3>
                <p class="mt-1 text-slate-600 dark:text-slate-400">
                    Port {{ connection.port || 21 }} only carries commands: login, change folder, delete. Every folder listing and file transfer
                    opens a second connection to one of the passive ports ({{ range }}). If those ports cannot be reached,
                    login works but listing and uploads fail.
                </p>
            </div>
            <dl class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-950 sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs uppercase text-slate-500">Public IP</dt><dd class="mt-1 font-mono">{{ server.public_ip || 'Unknown' }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Server's own IP</dt><dd class="mt-1 font-mono">{{ server.local_ip || 'Unknown' }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Passive ports</dt><dd class="mt-1 font-mono">TCP {{ range }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Network</dt><dd class="mt-1">{{ server.behind_nat ? 'Behind a router (NAT)' : 'Direct public IP' }}</dd></div>
            </dl>
        </template>

        <section v-if="showRouter" class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" :class="highlight('nat_forward')">
            <h3 class="font-semibold"><i class="bi bi-router"></i> Forward the ports on your router</h3>
            <p class="mt-1 text-slate-600 dark:text-slate-400">
                This server sits behind a router ({{ server.local_ip }}), so the internet reaches the router, not the server. Add these
                port forwarding rules on the router. The menu is usually called Port Forwarding, Virtual Server or NAT
                (TP-Link: Advanced → NAT Forwarding → Virtual Servers).
            </p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[480px] text-left">
                    <thead class="text-xs uppercase text-slate-500"><tr><th class="py-1.5 pr-4">Name</th><th class="py-1.5 pr-4">Protocol</th><th class="py-1.5 pr-4">External port</th><th class="py-1.5 pr-4">Internal IP</th><th class="py-1.5">Internal port</th></tr></thead>
                    <tbody class="font-mono">
                        <tr v-for="rule in routerRules" :key="rule.name" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-1.5 pr-4 font-sans">{{ rule.name }}</td><td class="py-1.5 pr-4">TCP</td><td class="py-1.5 pr-4">{{ rule.external }}</td><td class="py-1.5 pr-4">{{ server.local_ip }}</td><td class="py-1.5">{{ rule.internal }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-slate-600 dark:text-slate-400">
                <li>Give this server a fixed IP ({{ server.local_ip }}) in the router's DHCP settings, or the rules stop working when it changes.</li>
                <li>If the router cannot forward a range this large, shrink it: <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">DPANEL_FTP_PASV_MIN_PORT=30000 DPANEL_FTP_PASV_MAX_PORT=30010 dpanel module ftp update</code>, then forward that range.</li>
                <li>If your internet provider changes the public IP, run <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">dpanel module ftp update</code> so FTP announces the new one.</li>
            </ul>
        </section>

        <section v-if="showFirewall" class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" :class="highlight('firewall')">
            <h3 class="font-semibold"><i class="bi bi-shield-check"></i> Open the ports in the firewall</h3>
            <p class="mt-1 text-slate-600 dark:text-slate-400">
                Allow TCP {{ connection.port || 21 }} and TCP {{ range }} inbound. <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">dpanel module ftp update</code>
                opens them in ufw on this server. If your provider has its own firewall (security group, cloud firewall), open the same ports there too.
            </p>
        </section>

        <section v-if="showHairpin" class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" :class="highlight('no_hairpin')">
            <h3 class="font-semibold"><i class="bi bi-arrow-repeat"></i> Test from outside this network</h3>
            <p class="mt-1 text-slate-600 dark:text-slate-400">
                This server cannot reach its own public IP ({{ server.public_ip }}), which many routers do not allow. That does not mean
                clients are blocked. Connect from a computer on another network (for example a phone hotspot) with FileZilla to check.
            </p>
        </section>

        <section v-if="showPublicIp" class="rounded-lg border border-slate-200 p-4 dark:border-slate-700" :class="highlight('no_public_ip')">
            <h3 class="font-semibold"><i class="bi bi-globe2"></i> Set the public IP</h3>
            <p class="mt-1 text-slate-600 dark:text-slate-400">
                FTP does not know this server's public IP, so it tells clients an address they cannot reach. Run
                <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">DPANEL_FTP_PASV_ADDRESS=&lt;public IP&gt; dpanel module ftp update</code>.
            </p>
        </section>
    </div>
</template>
