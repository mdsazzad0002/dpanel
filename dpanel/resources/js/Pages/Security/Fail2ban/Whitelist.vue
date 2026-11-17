<script setup>
import { onMounted } from 'vue';
import Fail2banShell from './components/Fail2banShell.vue';
import WhitelistCard from './components/WhitelistCard.vue';
import { useFail2ban } from './useFail2ban';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    clientIp: { type: String, default: '' },
});

const { status, loading, loadError, busy, message, load, whitelistAdd, whitelistRemove } = useFail2ban(props.clientIp);

onMounted(load);
</script>

<template>
    <Fail2banShell
        :title="t('security.fail2ban.pages.whitelistTitle')"
        :description="t('security.fail2ban.pages.whitelistDesc')"
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
