/**
 * `setup-prompts` — interactive clack prompts for the setup wizard.
 *
 * Split from `setup-wizard-defaults.ts` in Round-1.5 (replaces the
 * Round-1 LoC-exception spec-amendment with a structural code-fix).
 *
 * Cohesion:
 *  - this file owns every clack/inquirer-style prompt sequence
 *    (`defaultPrompt`, `defaultConfirmContinue`) — pure terminal
 *    I/O, no REST / file-system side effects.
 *  - `setup-wizard-defaults.ts` retains the real-I/O implementations
 *    (RestClient probes, file-system writers, rollback, handshake).
 *
 * @license MIT
 */

import {
    cancel,
    confirm as clackConfirm,
    intro,
    isCancel,
    multiselect,
    password,
    text,
} from '@clack/prompts';

import {
    ALL_CLIENTS,
    type DetectedClient,
} from './clients/index.js';
import { SERVER_NAME, SERVER_VERSION } from './server.js';
import { decodeToken, normaliseUrl } from './setup-token.js';
import type { WizardAnswers } from './setup-wizard-types.js';

/**
 * Interactive multi-step prompt sequence — Wave 6.5 UX-parity with
 * api-mapper:
 *
 *   1. password input for the Bearer key (length-validated)
 *   2. decode the token's signed payload (signature-unverified) and use
 *      `iss` to pre-fill the URL prompt — the typical user only needs
 *      to press Enter here
 *   3. text input for the profile name (default: `default`) — supports
 *      multi-site usage in a single wizard install
 *   4. multiselect AI clients (pre-populates with detected clients)
 *
 * Returns `null` when the user cancels any prompt — `runWizard`
 * treats that as a clean exit.
 */
export async function defaultPrompt(input: {
    detected: readonly DetectedClient[];
}): Promise<WizardAnswers | null> {
    intro(`${SERVER_NAME} v${SERVER_VERSION} — setup wizard`);

    // 1. Bearer key first. Decoded `iss` will pre-fill the URL prompt.
    const bearerRaw = await password({
        message: 'Bearer key (from wp-admin → Tools → "YT Builder MCP" → Bearer Keys):',
        validate: (v) => {
            const trimmed = v.trim();
            if (trimmed === '') return 'Bearer key is required.';
            if (trimmed.length < 16) {
                return 'Bearer key looks too short — expected at least 16 chars.';
            }
            return undefined;
        },
    });
    if (isCancel(bearerRaw)) {
        cancel('Setup cancelled.');
        return null;
    }
    const bearer = (bearerRaw as string).trim();

    const decoded = decodeToken(bearer);
    const urlInitial = decoded?.iss ?? '';

    // 2. WordPress site URL — pre-filled from the decoded `iss` claim
    //    when the token was decodable; the user just presses Enter.
    const wpUrlRaw = await text({
        message: urlInitial !== ''
            ? 'WordPress site URL (pre-filled from your key — press Enter to accept):'
            : 'WordPress site URL (e.g. https://example.com):',
        placeholder: urlInitial !== '' ? urlInitial : 'https://example.com',
        initialValue: urlInitial,
        validate: (v) => {
            const trimmed = v.trim();
            if (trimmed === '') return 'URL is required.';
            if (!/^https?:\/\//.test(trimmed)) {
                return 'URL must start with http:// or https://.';
            }
            try {
                void new URL(trimmed);
            } catch {
                return 'URL is not a valid web address.';
            }
            return undefined;
        },
    });
    if (isCancel(wpUrlRaw)) {
        cancel('Setup cancelled.');
        return null;
    }
    const wpUrl = normaliseUrl(wpUrlRaw as string);

    // 3. Profile name — multi-site switching. Default 'default'.
    const profileRaw = await text({
        message: 'Profile name (for switching between sites):',
        placeholder: 'default',
        initialValue: 'default',
        validate: (v) => {
            const trimmed = v.trim();
            if (trimmed === '') return 'Profile name is required.';
            if (!/^[a-zA-Z0-9_-]+$/.test(trimmed)) {
                return 'Use letters, digits, dashes, or underscores only.';
            }
            return undefined;
        },
    });
    if (isCancel(profileRaw)) {
        cancel('Setup cancelled.');
        return null;
    }
    const profileName = (profileRaw as string).trim();

    // 4. AI client selection (multi-select; pre-checks detected ones).
    const clientChoices = ALL_CLIENTS.map((c) => {
        const detection = input.detected.find((d) => d.id === c.id);
        return {
            value: c.id,
            label: detection?.detected === true ? `${c.label}  (detected)` : c.label,
            hint: detection?.configPath ?? '',
        };
    });

    const selectedRaw = await multiselect({
        message: 'Which AI clients should we configure?',
        options: clientChoices,
        initialValues: input.detected.filter((d) => d.detected).map((d) => d.id),
        required: true,
    });
    if (isCancel(selectedRaw)) {
        cancel('Setup cancelled.');
        return null;
    }

    return {
        wpUrl,
        bearer,
        selectedClients: selectedRaw as string[],
        profileName,
    };
}

/**
 * Yes/no confirmation prompt. Returns `false` on cancel (safer default
 * than `true` — the wizard treats it as "don't continue").
 */
export async function defaultConfirmContinue(message: string): Promise<boolean> {
    const ans = await clackConfirm({ message, initialValue: false });
    if (isCancel(ans)) return false;
    return ans === true;
}
