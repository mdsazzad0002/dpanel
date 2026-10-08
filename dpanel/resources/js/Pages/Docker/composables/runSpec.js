import { joinCommand, splitCommand } from './useDockerRequest';

export const blankSpec = () => ({
    image: '', name: '', restart: 'unless-stopped', ports: [], env: [], volumes: [],
    network: '', aliases: [], hostname: '', memory: '', cpus: '', entrypoint: '', command: [], pull: false,
});

/** A spec from the API (or a template) as editable form state. */
export const specToForm = (spec = {}) => {
    const base = { ...blankSpec(), ...spec };
    return {
        ...base,
        ports: (base.ports || []).map((p) => ({ host: p.host ?? '', container: p.container ?? '', protocol: p.protocol || 'tcp', public: Boolean(p.public) })),
        env: (base.env || []).map((e) => ({ key: e.key || '', value: e.value ?? '' })),
        volumes: (base.volumes || []).map((v) => ({ source: v.source || '', target: v.target || '', read_only: Boolean(v.read_only) })),
        aliasText: (base.aliases || []).join(', '),
        commandText: joinCommand(base.command || []),
    };
};

/** Form state as the spec the panel validates. */
export const formToSpec = (form) => ({
    image: form.image.trim(),
    name: form.name.trim(),
    restart: form.restart,
    ports: form.ports.filter((p) => p.host && p.container).map((p) => ({ host: Number(p.host), container: Number(p.container), protocol: p.protocol || 'tcp', public: Boolean(p.public) })),
    env: form.env.filter((e) => e.key.trim()).map((e) => ({ key: e.key.trim(), value: e.value })),
    volumes: form.volumes.filter((v) => v.source.trim() && v.target.trim()).map((v) => ({ source: v.source.trim(), target: v.target.trim(), read_only: Boolean(v.read_only) })),
    network: form.network.trim(),
    aliases: form.network.trim() ? form.aliasText.split(/[\s,]+/).map((a) => a.trim()).filter(Boolean) : [],
    hostname: form.hostname.trim(),
    memory: form.memory.trim(),
    cpus: String(form.cpus ?? '').trim(),
    entrypoint: form.entrypoint.trim(),
    command: splitCommand(form.commandText),
    pull: Boolean(form.pull),
});

/** `KEY=value` lines (a .env file) as variables; blank lines and # comments are skipped. */
export const parseEnvLines = (text) => String(text || '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#') && line.includes('='))
    .map((line) => {
        const at = line.indexOf('=');
        let value = line.slice(at + 1).trim();
        if (/^(['"]).*\1$/.test(value)) value = value.slice(1, -1);
        return { key: line.slice(0, at).replace(/^export\s+/, '').trim(), value };
    });

const parsePort = (value, publicByDefault = true) => {
    // [ip:]host:container[/proto]  or  container[/proto]
    const [main, protocol = 'tcp'] = value.split('/');
    const parts = main.split(':');
    if (parts.length === 1) return { host: Number(parts[0]), container: Number(parts[0]), protocol, public: false };
    const container = Number(parts.pop());
    const host = Number(parts.pop());
    const ip = parts.join(':');
    return { host, container, protocol, public: ip ? !ip.startsWith('127.') : publicByDefault };
};

/**
 * Reads a `docker run …` command (as Docker Hub pages show them) into a
 * spec. Ports without an address are kept private, since the panel serves
 * apps through a domain; flags the panel has no field for are reported.
 */
export function parseDockerRun(text) {
    const args = splitCommand(String(text || '').replace(/\\\r?\n/g, ' '));
    while (args.length && ['sudo', 'docker', 'run', 'container'].includes(args[0])) args.shift();
    const spec = blankSpec();
    spec.restart = 'unless-stopped';
    const ignored = [];
    const takesValue = new Set(['-p', '--publish', '-e', '--env', '-v', '--volume', '--name', '--restart', '--network', '--net', '--network-alias', '-h', '--hostname', '-m', '--memory', '--cpus', '--entrypoint', '--pull', '--env-file', '-w', '--workdir', '-u', '--user', '--label', '-l', '--add-host', '--log-opt', '--ulimit', '--mount', '--shm-size', '--platform', '--device', '--cap-add', '--cap-drop', '--dns', '--tmpfs']);
    let i = 0;
    for (; i < args.length; i += 1) {
        let arg = args[i];
        if (!arg.startsWith('-')) break;
        let value = null;
        if (arg.startsWith('--') && arg.includes('=')) {
            [arg, value] = [arg.slice(0, arg.indexOf('=')), arg.slice(arg.indexOf('=') + 1)];
        } else if (/^-[a-zA-Z]{2,}$/.test(arg)) {
            // Bundled short flags such as -dit: none of them take a value here.
            continue;
        } else if (takesValue.has(arg)) {
            value = args[++i] ?? '';
        }
        switch (arg) {
            case '-p': case '--publish': spec.ports.push(parsePort(value, false)); break;
            case '-e': case '--env': {
                const at = value.indexOf('=');
                spec.env.push(at === -1 ? { key: value, value: '' } : { key: value.slice(0, at), value: value.slice(at + 1) });
                break;
            }
            case '-v': case '--volume': {
                const [source, target, mode] = value.split(':');
                if (target) spec.volumes.push({ source, target, read_only: mode === 'ro' });
                break;
            }
            case '--name': spec.name = value; break;
            case '--restart': spec.restart = value.split(':')[0]; break;
            case '--network': case '--net': spec.network = ['bridge', 'default'].includes(value) ? '' : value; break;
            case '--network-alias': spec.aliases.push(value); break;
            case '-h': case '--hostname': spec.hostname = value; break;
            case '-m': case '--memory': spec.memory = value.toLowerCase().replace(/([kmg])b$/, '$1'); break;
            case '--cpus': spec.cpus = value; break;
            case '--entrypoint': spec.entrypoint = value; break;
            case '--pull': spec.pull = value === 'always'; break;
            case '-d': case '--detach': case '-i': case '-t': case '--interactive': case '--tty': case '--rm': break;
            default: ignored.push(value === null ? arg : `${arg} ${value}`);
        }
    }
    spec.image = args[i] || '';
    spec.command = args.slice(i + 1);
    return { spec, ignored };
}
