export const fmtBytes = (bytes) => {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / 1024 ** i).toFixed(i >= 3 ? 1 : 0)} ${units[i]}`;
};

export const fmtIdle = (seconds) => {
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
};

export const percent = (pull) => (pull.total_bytes ? Math.floor((pull.downloaded_bytes / pull.total_bytes) * 100) : null);

// "1.2 MB/s · 4m left" for a running download; empty until a speed is known.
export const fmtProgress = (pull) => {
    if (!pull.speed_bps) return '';
    const parts = [`${fmtBytes(pull.speed_bps)}/s`];
    if (pull.total_bytes) {
        parts.push(`${fmtIdle(Math.ceil((pull.total_bytes - pull.downloaded_bytes) / pull.speed_bps))} left`);
    }
    return parts.join(' · ');
};
