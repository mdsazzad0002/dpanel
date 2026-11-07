import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const normalize = (path) => String(path || '').trim().replace(/\\/g, '/');

// Windows paths ignore case, as PathService does on the server.
const comparable = (path) => (/^[a-z]:/i.test(path) ? path.toLowerCase() : path);

/** The folder website homes live in, which is /home only on a Linux server. */
export function useServerBaseDir() {
    const page = usePage();
    const baseDir = computed(() => normalize(page.props.panel?.serverBaseDir || '/home').replace(/\/+$/, ''));

    const ownerHome = (owner) => `${baseDir.value}/${owner}`;
    const isInsideBase = (path) => comparable(normalize(path)).startsWith(`${comparable(baseDir.value)}/`);

    return { baseDir, ownerHome, isInsideBase };
}
