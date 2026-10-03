<script setup>
import { onMounted } from 'vue';
import Fail2banShell from './components/Fail2banShell.vue';
import WhitelistCard from './components/WhitelistCard.vue';
import { useFail2ban } from './useFail2ban';

const props = defineProps({
    clientIp: { type: String, default: '' },
});

const { status, loading, loadError, busy, message, load, whitelistAdd, whitelistRemove } = useFail2ban(props.clientIp);

onMounted(load);
</script>

<template>
    <Fail2banShell
        title="Whitelist"
        description="IPs fail2ban never blocks, however many logins fail."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        @refresh="load"
    >
        <WhitelistCard
            :entries="status.whitelist"
            :busy="busy"
            @add="whitelistAdd"
            @remove="whitelistRemove"
        />
    </Fail2banShell>
</template>
