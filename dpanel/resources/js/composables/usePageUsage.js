// How often each panel link is clicked, kept in this browser's localStorage per
// user and never sent to the server. Every <a> in the panel counts (sidebar,
// dashboard, buttons, search results), so the dashboard's Quick Access can
// put the most clicked tools first.
const KEY_PREFIX = 'dpanel.usage.v2.';
const MAX_ENTRIES = 100;

const keyFor = (userId) => `${KEY_PREFIX}${userId ?? 'guest'}`;

// Panel URLs carry a per-session /dpt<token> prefix and query strings;
// strip both so the same tool keeps one counter across logins.
export const normalizeLink = (href) => {
    try {
        const url = new URL(href, window.location.origin);
        if (url.origin !== window.location.origin) return null;
        const path = url.pathname.replace(/^\/dpt[0-9a-fA-F]+(?=\/|$)/, '').replace(/\/+$/, '');
        return path || '/';
    } catch {
        return null;
    }
};

export const readPageUsage = (userId) => {
    try {
        const saved = JSON.parse(window.localStorage.getItem(keyFor(userId)) ?? 'null');
        return saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
    } catch {
        return {};
    }
};

export const clearPageUsage = (userId) => {
    try {
        window.localStorage.removeItem(keyFor(userId));
    } catch {
        // Blocked storage: nothing was saved either.
    }
};

// Most clicks first; on a tie the one clicked most recently.
export const compareUsage = (a, b) => (b?.count ?? 0) - (a?.count ?? 0) || (b?.last ?? 0) - (a?.last ?? 0);

export const recordLinkClick = (userId, href) => {
    const path = normalizeLink(href);
    if (!userId || !path) return;

    const usage = readPageUsage(userId);
    const entry = usage[path] ?? { count: 0, last: 0 };
    usage[path] = { count: entry.count + 1, last: Date.now() };

    const kept = Object.entries(usage)
        .sort(([, a], [, b]) => compareUsage(a, b))
        .slice(0, MAX_ENTRIES);

    try {
        window.localStorage.setItem(keyFor(userId), JSON.stringify(Object.fromEntries(kept)));
    } catch {
        // Private mode / blocked storage: Quick Access falls back to suggestions.
    }
};

// After a clicked link has loaded, remember what the page was (its title and the
// website or database it is about) so Quick Access can show pages that are not
// in the sidebar, like one website's Manage or Terminal page. Only names are
// stored, never other page data.
export const describePage = (userId, href, title, subject) => {
    const path = normalizeLink(href);
    if (!userId || !path || !title) return;

    const usage = readPageUsage(userId);
    if (!usage[path]) return;
    usage[path] = { ...usage[path], title, subject: subject || null };

    try {
        window.localStorage.setItem(keyFor(userId), JSON.stringify(usage));
    } catch {
        // Blocked storage: the entry just keeps its old title.
    }
};

// Counts left-clicks and middle-clicks (open in new tab) on any same-site link,
// plus visits started from code, like opening a search result with Enter.
export const trackLinkClicks = (router, getUserId) => {
    // An Inertia <Link> click also starts a visit; skip that visit so it isn't counted twice.
    let lastClick = { path: null, at: 0 };

    const onClick = (event) => {
        if (event.button !== 0 && event.button !== 1) return;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || link.hasAttribute('download')) return;
        recordLinkClick(getUserId(), link.href);
        lastClick = { path: normalizeLink(link.href), at: Date.now() };
    };
    document.addEventListener('click', onClick, true);
    document.addEventListener('auxclick', onClick, true);

    router.on('before', (event) => {
        const visit = event.detail.visit;
        if (visit.method !== 'get' || visit.prefetch || visit.only?.length || visit.except?.length) return;

        const href = String(visit.url);
        const path = normalizeLink(href);
        // Reloads and polling revisit the page that is already open.
        if (!path || path === normalizeLink(window.location.href)) return;
        if (path === lastClick.path && Date.now() - lastClick.at < 1000) return;

        recordLinkClick(getUserId(), href);
    });
};
