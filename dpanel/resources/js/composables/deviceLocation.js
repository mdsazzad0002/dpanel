/**
 * The browser's own position, for the login audit. Most browsers give it
 * only on HTTPS (and localhost); some, or some settings, allow it on HTTP
 * too, so we always let the browser decide.
 */
import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

export const canReadLocation = () => typeof window !== 'undefined' && 'geolocation' in navigator;

// Error codes of the Geolocation API with a message each (read when shown, so it follows the language).
const ERROR_KEYS = { 1: 'blocked', 2: 'unavailable', 3: 'timeout' };

/** Resolves {device_latitude, device_longitude, device_accuracy}; rejects with a readable message. */
export const readDeviceLocation = () => new Promise((resolve, reject) => {
    if (!canReadLocation()) {
        reject(new Error(t('ui.location.unsupported')));
        return;
    }
    navigator.geolocation.getCurrentPosition(
        (position) => resolve({
            device_latitude: position.coords.latitude,
            device_longitude: position.coords.longitude,
            device_accuracy: Math.round(position.coords.accuracy),
        }),
        (error) => reject(new Error(
            error.code === 1 && !window.isSecureContext
                ? t('ui.location.needsHttps')
                : t(ERROR_KEYS[error.code] ? `ui.location.errors.${ERROR_KEYS[error.code]}` : 'ui.location.failed'),
        )),
        { enableHighAccuracy: true, timeout: 20000, maximumAge: 60000 },
    );
});
