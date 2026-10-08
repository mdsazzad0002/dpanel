<script setup>
import { onMounted, ref } from 'vue';
import DockerShell from './components/DockerShell.vue';
import RunContainerCard from './components/RunContainerCard.vue';
import ContainerList from './components/ContainerList.vue';
import ContainerLogs from './components/ContainerLogs.vue';
import { useDocker } from './useDocker';

const { status, loading, loadError, busy, message, load, errorText, containerAction, runContainer, fetchLogs } = useDocker();

const logsFor = ref(null);

onMounted(load);
</script>

<template>
    <DockerShell
        title="Docker Containers"
        description="Run, stop and inspect the containers on this server."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        @refresh="load"
    >
        <RunContainerCard v-if="status.running" :busy="busy" :run="runContainer" />

        <ContainerList
            :containers="status.containers"
            :loading="loading"
            :busy="busy"
            @action="containerAction"
            @logs="logsFor = $event"
        />

        <ContainerLogs :container="logsFor" :fetch-logs="fetchLogs" :error-text="errorText" @close="logsFor = null" />
    </DockerShell>
</template>
