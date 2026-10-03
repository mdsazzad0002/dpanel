<script setup>
import { onMounted } from 'vue';
import Fail2banShell from './components/Fail2banShell.vue';
import SshPolicyCard from './components/SshPolicyCard.vue';
import SshLoginHistory from './components/SshLoginHistory.vue';
import { useFail2ban } from './useFail2ban';

const props = defineProps({
    clientIp: { type: String, default: '' },
});

const {
    status, loading, loadError, busy, message, load, savePolicy,
    history, historyLoading, historyError, loadHistory, unban, ban, bulk,
} = useFail2ban(props.clientIp);

const refresh = () => {
    load();
    loadHistory();
};

onMounted(refresh);
</script>

<template>
    <Fail2banShell
        title="SSH Login History"
        description="Recent SSH logins and failed attempts. Block an IP that keeps trying, or unblock one fail2ban caught."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        @refresh="refresh"
    >
        <SshPolicyCard
            v-if="status.running"
            :policy="status.policy"
            :busy="busy === 'policy'"
            @save="savePolicy"
        />

        <SshLoginHistory
            :events="history"
            :jails="status.jails"
            :whitelist="status.whitelist"
            :client-ip="props.clientIp"
            :loading="historyLoading"
            :error="historyError"
            :busy="busy"
            @unban="unban"
            @ban="ban"
            @bulk="bulk"
            @refresh="loadHistory"
        />
    </Fail2banShell>
</template>
