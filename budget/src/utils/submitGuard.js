/**
 * One run at a time for actions that create something.
 */

const running = new Set();

/**
 * Run `work` unless a run under the same key is still going, with the
 * button disabled meanwhile. A double click (or Enter pressed twice) on a
 * create action used to create two of everything.
 *
 * @template T
 * @param {string} key names the action
 * @param {HTMLElement|null} button disabled while it runs
 * @param {() => Promise<T>} work
 * @return {Promise<T|undefined>} undefined when a run was already going
 */
export async function once(key, button, work) {
    if (running.has(key)) {
        return undefined;
    }
    running.add(key);
    if (button) button.disabled = true;
    try {
        return await work();
    } finally {
        running.delete(key);
        if (button) button.disabled = false;
    }
}
