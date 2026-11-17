<script setup>
import { onMounted } from 'vue';
import Fail2banShell from './components/Fail2banShell.vue';
import SshPolicyCard from './components/SshPolicyCard.vue';
import SshLoginHistory from './components/SshLoginHistory.vue';
import { useFail2ban } from './useFail2ban';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    clientIp: { type: String, default: '' },
});

const {
    status, loading, loadError, busy, message, load, savePolicy,
    history, historyLoading, historyError, historyHidden, loadHistory, unban, ban, bulk,
    deleteHistory, clearHistory, restoreHistory,
} = useFail2ban(props.clientIp);

const refresh = () => {
    load();
    loadHistory();
};

onMounted(refresh);
</script>

<template>
    <Fail2banShell
        :title="t('security.fail2ban.pages.sshTitle')"
        :description="t('security.fail2ban.pages.sshDesc')"
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
            :hidden="historyHidden"
            :busy="busy"
            @unban="unban"
            @ban="ban"
            @bulk="bulk"
            @delete="deleteHistory"
            @clear="clearHistory"
            @restore="restoreHistory"
            @refresh="loadHistory"
        />
    </Fail2banShell>
</template>
