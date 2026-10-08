<script setup>
import { onMounted } from 'vue';
import DockerShell from './components/DockerShell.vue';
import PullImageCard from './components/PullImageCard.vue';
import ImageList from './components/ImageList.vue';
import { useDocker } from './useDocker';

const { status, loading, loadError, busy, message, load, pullImage, removeImage, pruneImages } = useDocker();

onMounted(load);
</script>

<template>
    <DockerShell
        title="Docker Images"
        description="Pull images from a registry and clean up the ones you no longer use."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        @refresh="load"
    >
        <PullImageCard v-if="status.running" :busy="busy" @pull="pullImage" @prune="pruneImages" />

        <ImageList
            :images="status.images"
            :containers="status.containers"
            :loading="loading"
            :busy="busy"
            @remove="removeImage"
        />
    </DockerShell>
</template>
