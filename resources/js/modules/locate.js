// window.drrmsLocate(): the phone's location for the Reporter screens (report,
// SOS, check-in, map). Precise GPS first; if that is slow or unavailable
// (indoors, GPS off), once more with the quicker network location. Resolves
// { latitude, longitude, accuracy }; rejects with an Error whose message says
// what to do (permission blocked, Location off, too slow).

const MESSAGES = {
    insecure: 'Location only works on the secure (https://) address of this site.',
    unsupported: 'This browser can’t share a location.',
    1: 'Location is blocked for this site. Tap the icon left of the web address, open Permissions (or Site settings), set Location to Allow, then try again.',
    2: 'Your phone couldn’t find where you are. Turn on Location in the phone’s quick settings, then try again.',
    3: 'Finding your location took too long. Move near a window or outside, then try again.',
};

function position(options) {
    return new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, options));
}

function failure(key) {
    const error = new Error(MESSAGES[key] || MESSAGES[2]);
    error.code = key;
    return error;
}

window.drrmsLocate = async function () {
    if (! window.isSecureContext) throw failure('insecure');
    if (! navigator.geolocation) throw failure('unsupported');

    let p;
    try {
        p = await position({ enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 });
    } catch (error) {
        if (error.code === 1) throw failure(1);
        try {
            p = await position({ enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 });
        } catch (retry) {
            throw failure(retry.code === 1 ? 1 : (retry.code || error.code));
        }
    }

    return { latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: Math.round(p.coords.accuracy) };
};
