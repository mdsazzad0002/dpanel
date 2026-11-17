<script setup>
import { onMounted } from 'vue';
import Fail2banShell from './components/Fail2banShell.vue';
import BlockIpCard from './components/BlockIpCard.vue';
import BannedIpList from './components/BannedIpList.vue';
import { useFail2ban } from './useFail2ban';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    clientIp: { type: String, default: '' },
});

const { status, loading, loadError, busy, message, load, unban, ban, whitelistAdd } = useFail2ban(props.clientIp);

onMounted(load);
</script>

<template>
    <Fail2banShell
        :title="t('security.fail2ban.pages.blocklistTitle')"
        :description="t('security.fail2ban.pages.blocklistDesc')"
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        @refresh="load"
    >
        <BlockIpCard
            v-if="status.running"
            :busy="busy"
            @block="ban"
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
    </Fail2banShell>
</template>
