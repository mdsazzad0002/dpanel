<script setup>
import { onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import DockerShell from './components/DockerShell.vue';
import PullImageCard from './components/images/PullImageCard.vue';
import ImageList from './components/images/ImageList.vue';
import { useDocker } from './composables/useDocker';

const { status, loading, loadError, busy, message, load, pullImage, removeImage, pruneImages, panelRoute } = useDocker();

// "Run" on an image opens the Containers page with the form filled in.
const run = (image) => router.visit(`${panelRoute('docker.containers')}?run=${encodeURIComponent(image.label)}`);

onMounted(load);
</script>

<template>
    <DockerShell
        title="Docker Images"
        description="Pull images, run containers from them and clean up the ones you no longer use."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        @refresh="load"
        @dismiss="message = null"
    >
        <PullImageCard v-if="status.running" :busy="busy" @pull="pullImage" @prune="pruneImages" />

        <ImageList
            :images="status.images"
            :containers="status.containers"
            :loading="loading"
            :busy="busy"
            @remove="removeImage"
            @run="run"
            @pull="pullImage"
        />
    </DockerShell>
</template>
