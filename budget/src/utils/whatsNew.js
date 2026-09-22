/**
 * "What's new" popup: which release notes a user is shown after an update.
 *
 * The notes are hand-written per release in src/whatsnew.json. A release with
 * no entry shows nothing. The version a user last saw is kept in the per-user
 * setting WHATS_NEW_SEEN_KEY, so each release's notes appear once, the first
 * time the app loads on that version.
 */

/** Per-user setting holding the last version whose notes were shown. */
export const WHATS_NEW_SEEN_KEY = 'whats_new_seen';

/** Most releases shown at once, however long the user went without updating. */
export const MAX_ENTRIES = 3;

/**
 * Compare two dotted version strings part by part. A missing part counts as
 * zero, so '2.54' equals '2.54.0'.
 *
 * @return {number} negative if a < b, positive if a > b, 0 if equal.
 */
export function compareVersions(a, b) {
    const pa = String(a).split('.').map(n => parseInt(n, 10) || 0);
    const pb = String(b).split('.').map(n => parseInt(n, 10) || 0);
    for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
        const diff = (pa[i] || 0) - (pb[i] || 0);
        if (diff !== 0) return diff;
    }
    return 0;
}

/** Entries released up to and including `version`, newest first. */
function upTo(entries, version) {
    return entries
        .filter(e => compareVersions(e.version, version) <= 0)
        .sort((a, b) => compareVersions(b.version, a.version));
}

/**
 * The notes to pop up on this load.
 *
 * - A brand-new user (no last-seen version and no accounts) gets nothing:
 *   release notes mean nothing on day one.
 * - An existing user with no last-seen version (the release that introduced
 *   the popup) gets the latest notes.
 * - Otherwise every release newer than the one last seen, up to the installed
 *   version, newest first and capped at MAX_ENTRIES.
 *
 * @param {Array<{version: string}>} entries
 * @param {{currentVersion: string, lastSeen: string, isNewUser: boolean}} state
 */
export function entriesToShow(entries, { currentVersion, lastSeen, isNewUser }) {
    if (!currentVersion) return [];
    if (!lastSeen && isNewUser) return [];

    return upTo(entries, currentVersion)
        .filter(e => !lastSeen || compareVersions(e.version, lastSeen) > 0)
        .slice(0, MAX_ENTRIES);
}

/** The newest notes up to the installed version, for the Help page's button. */
export function latestEntries(entries, currentVersion) {
    return upTo(entries, currentVersion).slice(0, MAX_ENTRIES);
}

/** The app version the page was rendered with (PageController → index.php). */
export function installedVersion() {
    return document.getElementById('app-content')?.dataset.appVersion || '';
}
