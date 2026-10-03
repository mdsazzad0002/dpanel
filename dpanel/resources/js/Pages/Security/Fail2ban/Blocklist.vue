<script setup>
import { onMounted } from 'vue';
import Fail2banShell from './components/Fail2banShell.vue';
import YourIpCard from './components/YourIpCard.vue';
import BannedIpList from './components/BannedIpList.vue';
import SshPolicyCard from './components/SshPolicyCard.vue';
import { useFail2ban } from './useFail2ban';

const props = defineProps({
    clientIp: { type: String, default: '' },
});

const { status, loading, loadError, busy, message, bannedIn, load, unban, savePolicy, whitelistAdd } = useFail2ban(props.clientIp);

onMounted(load);
</script>

<template>
    <Fail2banShell
        title="Blocklist"
        description="IPs blocked after repeated failed logins. Unblock one, or whitelist it so it is never blocked again."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        @refresh="load"
    >
        <YourIpCard
            :ip="props.clientIp"
            :banned-in="bannedIn(props.clientIp)"
            :whitelisted="status.whitelist.includes(props.clientIp)"
            :loading="loading"
            :busy="busy === props.clientIp"
            @unban="unban"
            @whitelist="whitelistAdd"
        />

        <BannedIpList
            :jails="status.jails"
            :whitelist="status.whitelist"
            :client-ip="props.clientIp"
            :loading="loading"
            :busy="busy"
            @unban="unban"
            @whitelist="whitelistAdd"
        />

        <SshPolicyCard
            v-if="status.running"
            :policy="status.policy"
            :busy="busy === 'policy'"
            @save="savePolicy"
        />
    </Fail2banShell>
</template>
