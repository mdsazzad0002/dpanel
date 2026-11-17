// Shared by the FTP module pages.
import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

export const generatePassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    return Array.from(crypto.getRandomValues(new Uint32Array(20)), (value) => chars[value % chars.length]).join('');
};

export const securityLabel = (connection) => (connection?.tls ? t('ftp.security.tls') : t('ftp.security.plain'));

export const copyText = async (text) => {
    try {
        await navigator.clipboard.writeText(String(text ?? ''));
        return true;
    } catch {
        return false;
    }
};

export const clients = [
    { name: 'FileZilla', platforms: 'Windows, macOS, Linux', icon: 'bi-hdd-network', url: 'https://filezilla-project.org/download.php?type=client' },
    { name: 'WinSCP', platforms: 'Windows', icon: 'bi-windows', url: 'https://winscp.net/eng/download.php' },
    { name: 'Cyberduck', platforms: 'Windows, macOS', icon: 'bi-apple', url: 'https://cyberduck.io/download/' },
    { name: 'VS Code SFTP', get platforms() { return t('ftp.clients.vscodePlatforms'); }, icon: 'bi-code-square', url: 'https://marketplace.visualstudio.com/items?itemName=Natizyskunk.sftp' },
];
