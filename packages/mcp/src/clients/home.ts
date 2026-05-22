/**
 * `userHome()` — single source of truth for "where do AI clients live?".
 *
 * Returns `process.env.HOME` (or `USERPROFILE` on Windows) if set, falling
 * back to `os.homedir()`. The env-first ordering is important for tests:
 * Node's `os.homedir()` on POSIX uses `getpwuid_r`, which IGNORES the HOME
 * env override — so tests that set `process.env.HOME = tmpDir` would
 * otherwise stomp the real Claude / Cursor / Zed configs on the
 * developer's machine. Hard-learned during Wave-4 setup.
 *
 * @license MIT
 */

import { homedir } from 'node:os';

export function userHome(): string {
    const home = process.env.HOME ?? process.env.USERPROFILE;
    if (home !== undefined && home !== '') return home;
    return homedir();
}
