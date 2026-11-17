import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

/** The Docker sections, their menu routes and their "g then key" shortcut. */
export const sections = [
    { key: 'o', route: 'docker.overview', icon: 'bi bi-speedometer2' },
    { key: 'c', route: 'docker.containers', icon: 'bi bi-boxes' },
    { key: 's', route: 'docker.stacks', icon: 'bi bi-stack' },
    { key: 't', route: 'docker.templates', icon: 'bi bi-grid-3x3-gap' },
    { key: 'i', route: 'docker.images', icon: 'bi bi-layers' },
    { key: 'n', route: 'docker.networks', icon: 'bi bi-diagram-3' },
    { key: 'v', route: 'docker.volumes', icon: 'bi bi-hdd-stack' },
].map((section) => ({
    ...section,
    get label() { return t(`docker.nav.sections.${section.key}`); },
}));
