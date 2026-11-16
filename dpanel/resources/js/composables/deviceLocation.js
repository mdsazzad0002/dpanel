/**
 * The browser's own position, for the login audit. Most browsers give it
 * only on HTTPS (and localhost); some, or some settings, allow it on HTTP
 * too, so we always let the browser decide.
 */
export const canReadLocation = () => typeof window !== 'undefined' && 'geolocation' in navigator;

const ERRORS = {
    1: 'Location access was blocked. Allow location for this site in your browser settings, then try again.',
    2: 'Your device could not find its location. Turn on location services and try again.',
    3: 'Getting your location took too long. Try again.',
};

/** Resolves {device_latitude, device_longitude, device_accuracy}; rejects with a readable message. */
export const readDeviceLocation = () => new Promise((resolve, reject) => {
    if (!canReadLocation()) {
        reject(new Error('This browser has no location support.'));
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
                ? 'This browser gives no location over plain HTTP. Open the panel over HTTPS.'
                : (ERRORS[error.code] || 'Could not read your location.'),
        )),
        { enableHighAccuracy: true, timeout: 20000, maximumAge: 60000 },
    );
});
