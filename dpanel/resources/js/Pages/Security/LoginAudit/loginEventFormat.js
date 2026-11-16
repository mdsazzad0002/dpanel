/** Display helpers shared by the Login Audit table, map and detail view. */

export const RESULT_BADGES = {
    success: { label: 'Success', class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' },
    failed: { label: 'Failed', class: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' },
    logout: { label: 'Logout', class: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' },
};

export const CHANNEL_BADGES = {
    panel: { label: 'Panel', class: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' },
    ssh: { label: 'SSH', class: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300' },
};

export const formatTime = (value) => (value ? new Date(value).toLocaleString() : '—');

export const flag = (code) => (code && code.length === 2
    ? String.fromCodePoint(...[...code.toUpperCase()].map((c) => 0x1f1a5 + c.charCodeAt(0)))
    : '');

export const place = (event) => {
    if (event.geo_status === 'private') return 'Private network';
    if (event.geo_status === 'pending') return 'Location pending';
    const parts = [event.city, event.region, event.country].filter(Boolean);
    return parts.length ? [...new Set(parts)].join(', ') : 'Unknown';
};

/** A rough browser / OS / device read of a user agent; enough for an audit row. */
export const parseAgent = (agent) => {
    if (!agent) return null;
    const pick = (rules) => rules.find(([pattern]) => pattern.test(agent))?.[1] || 'Unknown';
    const version = (pattern) => agent.match(pattern)?.[1] || '';

    const browser = pick([
        [/Edg\//, `Edge ${version(/Edg\/([\d.]+)/)}`],
        [/OPR\//, `Opera ${version(/OPR\/([\d.]+)/)}`],
        [/Firefox\//, `Firefox ${version(/Firefox\/([\d.]+)/)}`],
        [/Chrome\//, `Chrome ${version(/Chrome\/([\d.]+)/)}`],
        [/Safari\//, `Safari ${version(/Version\/([\d.]+)/)}`],
        [/curl\//i, 'curl'],
    ]).trim();
    const os = pick([
        [/Windows NT 10/, 'Windows 10/11'],
        [/Windows/, 'Windows'],
        [/Android/, `Android ${version(/Android ([\d.]+)/)}`],
        [/iPhone|iPad/, `iOS ${version(/OS ([\d_]+)/).replace(/_/g, '.')}`],
        [/Mac OS X/, 'macOS'],
        [/CrOS/, 'ChromeOS'],
        [/Linux/, 'Linux'],
    ]).trim();
    const device = /Mobi|iPhone|Android(?!.*Tablet)/.test(agent) ? 'Mobile' : (/iPad|Tablet/.test(agent) ? 'Tablet' : 'Desktop');

    return { browser, os, device };
};

/** Where to put a login on the map: the browser's position if shared, else the IP's place. */
export const coordinates = (event) => (event.device_latitude != null
    ? { latitude: event.device_latitude, longitude: event.device_longitude, device: true }
    : (event.latitude != null ? { latitude: event.latitude, longitude: event.longitude, device: false } : null));

/** Great-circle distance in km between the IP place and the device position. */
export const distanceKm = (event) => {
    if (event.device_latitude == null || event.latitude == null) return null;
    const rad = (deg) => (deg * Math.PI) / 180;
    const dLat = rad(event.device_latitude - event.latitude);
    const dLng = rad(event.device_longitude - event.longitude);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(event.latitude)) * Math.cos(rad(event.device_latitude)) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.asin(Math.sqrt(a));
};

export const accuracyText = (metres) => (metres == null ? '' : (metres >= 1000 ? `±${(metres / 1000).toFixed(1)} km` : `±${metres} m`));
