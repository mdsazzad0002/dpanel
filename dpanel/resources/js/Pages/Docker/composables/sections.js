/** The Docker sections, their menu routes and their "g then key" shortcut. */
export const sections = [
    { key: 'o', route: 'docker.overview', label: 'Overview', icon: 'bi bi-speedometer2' },
    { key: 'c', route: 'docker.containers', label: 'Containers', icon: 'bi bi-boxes' },
    { key: 's', route: 'docker.stacks', label: 'Stacks', icon: 'bi bi-stack' },
    { key: 't', route: 'docker.templates', label: 'App templates', icon: 'bi bi-grid-3x3-gap' },
    { key: 'i', route: 'docker.images', label: 'Images', icon: 'bi bi-layers' },
    { key: 'n', route: 'docker.networks', label: 'Networks', icon: 'bi bi-diagram-3' },
    { key: 'v', route: 'docker.volumes', label: 'Volumes', icon: 'bi bi-hdd-stack' },
];
