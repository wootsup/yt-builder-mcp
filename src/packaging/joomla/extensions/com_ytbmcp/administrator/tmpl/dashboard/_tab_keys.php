<?php
/**
 * Dashboard — Bearer Keys tab partial (native Joomla/Bootstrap, Wave-11).
 *
 * Reveal-once token box (Site URL + Bearer token + copy buttons + DXT CTA +
 * Advanced setup disclosure) → generate form → existing-keys table with
 * per-row revoke. Parity with WP SettingsPage::render_keys_tab(), rendered
 * with native Bootstrap cards / tables / forms / alerts (no custom `.ytb-*`
 * colour classes, no inline style="").
 *
 * @var \WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView $this
 *
 * @license   GPL-2.0-or-later
 * @copyright (C) 2026 getimo productions
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use WootsUp\BuilderMcp\Platform\Joomla\Settings\JoomlaBrandStrings;
use WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView;

/** @var \WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView $this */
/** @var callable(string):string $esc */
$esc = $esc ?? static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$revealLede = explode("\n", JoomlaBrandStrings::REVEAL_TOKEN_LEDE);
?>
<div class="p-3">

<?php if ($this->revealedToken !== null && $this->revealedToken !== '') : ?>
    <?php // Reveal box — a native Bootstrap .card (NOT an .alert.alert-success).
          // Atum/Bootstrap recolours anchors inside contextual alerts with the
          // alert link colour (green + underline), which overrode the white
          // text of the .btn-primary download button. A plain .card keeps every
          // contained .btn rendering natively (white-on-blue, no underline). The
          // "ready" affirmation now lives in a success-coloured heading + check. ?>
    <div class="card border-success mb-4" role="status">
        <div class="card-body">
            <h2 class="card-title h5 text-success d-flex align-items-center gap-2">
                <span class="icon-check-circle" aria-hidden="true"></span>
                <?php echo $esc($revealLede[0] ?? 'Your key is ready'); ?>
            </h2>
            <p class="text-muted small"><?php echo $esc($revealLede[1] ?? ''); ?></p>

            <?php // Primary CTA — one-click Claude Desktop bundle (parity with WP render_revealed_token_notice). ?>
            <?php $dxtUrl = $this->siteUrl . '/media/com_ytbmcp/yt-builder-mcp.dxt'; ?>
            <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
                <a class="btn btn-primary btn-lg" href="<?php echo $esc($dxtUrl); ?>" download>
                    <span class="icon-download" aria-hidden="true"></span>
                    <?php echo Text::_('COM_YTBMCP_DOWNLOAD_DXT'); ?>
                </a>
                <span class="text-muted small"><?php echo $esc(JoomlaBrandStrings::REVEAL_TOKEN_PRIMARY_CTA_CAPTION); ?></span>
            </div>

            <?php // Site URL + token fields. Both copy buttons use the SAME native
                  // outline-secondary style (uniform across both fields + parity
                  // with the WP .button copy buttons). ?>
            <div class="mb-3">
                <label class="form-label fw-bold" for="ytb-mcp-site-url"><?php echo Text::_('COM_YTBMCP_SITE_URL'); ?></label>
                <div class="input-group">
                    <input type="text" id="ytb-mcp-site-url" class="form-control font-monospace" readonly value="<?php echo $esc($this->siteUrl); ?>">
                    <button type="button" class="btn btn-outline-secondary" data-ytb-copy="ytb-mcp-site-url" data-ytb-copy-status="ytb-mcp-copy-status-url"><?php echo Text::_('COM_YTBMCP_COPY'); ?></button>
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label fw-bold" for="ytb-mcp-revealed-token"><?php echo Text::_('COM_YTBMCP_BEARER_TOKEN'); ?></label>
                <div class="input-group">
                    <input type="text" id="ytb-mcp-revealed-token" class="form-control font-monospace" readonly value="<?php echo $esc($this->revealedToken); ?>">
                    <button type="button" class="btn btn-outline-secondary" data-ytb-copy="ytb-mcp-revealed-token" data-ytb-copy-status="ytb-mcp-copy-status-token"><?php echo Text::_('COM_YTBMCP_COPY'); ?></button>
                </div>
            </div>

        <p class="small mb-2 mt-3">
            <?php echo $esc(JoomlaBrandStrings::REVEAL_TOKEN_SAVE_WARNING); ?>
            <span id="ytb-mcp-copy-status-url" class="ms-2" aria-live="polite"></span>
            <span id="ytb-mcp-copy-status-token" class="ms-2" aria-live="polite"></span>
        </p>

        <?php // Advanced setup — collapsed by default ?>
        <details class="mt-2">
            <summary class="fw-bold"><?php echo $esc(JoomlaBrandStrings::ADVANCED_SECTION_SUMMARY); ?></summary>
            <div class="pt-3">
                <p class="mb-2"><strong><?php echo Text::_('COM_YTBMCP_MANUAL_SETUP'); ?></strong></p>
                <pre class="bg-dark text-light p-3 rounded"><code>npx -y @wootsup/yt-builder-mcp setup</code></pre>
                <p class="text-muted small mb-3"><?php echo Text::_('COM_YTBMCP_MANUAL_SETUP_HINT'); ?></p>

                <?php if ($this->pickupUrl !== '' && $this->pickupNonce !== '') : ?>
                    <?php
                    $aiPrompt = strtr(JoomlaBrandStrings::AI_PROMPT_TEMPLATE, [
                        '{pickupUrl}'   => $this->pickupUrl,
                        '{pickupNonce}' => $this->pickupNonce,
                        '{siteUrl}'     => $this->siteUrl,
                    ]);
                    ?>
                    <p class="mb-2"><strong><?php echo Text::_('COM_YTBMCP_AI_PROMPT_HEADING'); ?></strong>
                        <span class="text-muted small"><?php echo Text::_('COM_YTBMCP_AI_PROMPT_META'); ?></span></p>
                    <pre id="ytb-mcp-ai-prompt" class="bg-dark text-light p-3 rounded text-wrap"><code><?php echo $esc($aiPrompt); ?></code></pre>
                    <p class="mb-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-ytb-copy="ytb-mcp-ai-prompt" data-ytb-copy-status="ytb-mcp-copy-status-prompt"><?php echo Text::_('COM_YTBMCP_COPY_PROMPT'); ?></button>
                        <span id="ytb-mcp-copy-status-prompt" class="ms-2" aria-live="polite"></span>
                        <span class="text-muted small ms-2"><?php echo $esc(JoomlaBrandStrings::ADVANCED_SECTION_AI_PROMPT_CAVEAT); ?></span>
                    </p>
                <?php endif; ?>
            </div>
        </details>
        </div>
    </div>
<?php endif; ?>

<?php // --- Generate form --- ?>
<div class="card mb-4" id="ytb-mcp-generate">
    <div class="card-body">
        <h2 class="card-title h5"><?php echo Text::_('COM_YTBMCP_GENERATE_NEW_KEY'); ?></h2>
        <form method="post" action="index.php?option=com_ytbmcp">
            <div class="mb-3 row">
                <label class="col-sm-3 col-form-label" for="ytb-label"><?php echo Text::_('COM_YTBMCP_LABEL'); ?></label>
                <div class="col-sm-6">
                    <input id="ytb-label" type="text" name="label" class="form-control" required>
                </div>
            </div>
            <div class="mb-3 row">
                <label class="col-sm-3 col-form-label" for="ytb-scope"><?php echo Text::_('COM_YTBMCP_SCOPE'); ?></label>
                <div class="col-sm-6">
                    <select id="ytb-scope" name="scope" class="form-select">
                        <option value="read"><?php echo Text::_('COM_YTBMCP_SCOPE_READ'); ?></option>
                        <option value="write" selected><?php echo Text::_('COM_YTBMCP_SCOPE_WRITE'); ?></option>
                        <option value="admin"><?php echo Text::_('COM_YTBMCP_SCOPE_ADMIN'); ?></option>
                    </select>
                </div>
            </div>
            <div class="mb-3 row">
                <label class="col-sm-3 col-form-label" for="ytb-expires"><?php echo Text::_('COM_YTBMCP_EXPIRES'); ?></label>
                <div class="col-sm-6">
                    <select id="ytb-expires" name="expires" class="form-select">
                        <option value="90d" selected><?php echo Text::_('COM_YTBMCP_EXPIRES_90D'); ?></option>
                        <option value="1y"><?php echo Text::_('COM_YTBMCP_EXPIRES_1Y'); ?></option>
                        <option value="never"><?php echo Text::_('COM_YTBMCP_EXPIRES_NEVER'); ?></option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="task" value="dashboard.generateKey">
            <?php echo HTMLHelper::_('form.token'); ?>
            <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_YTBMCP_GENERATE_KEY'); ?></button>
        </form>
    </div>
</div>

<?php // --- Existing keys --- ?>
<h2 class="h5"><?php echo Text::_('COM_YTBMCP_EXISTING_KEYS'); ?></h2>

<?php if ($this->keys === []) : ?>
    <p><?php echo $esc(JoomlaBrandStrings::EMPTY_STATE_HEADLINE); ?></p>
    <p>
        <?php echo $esc(JoomlaBrandStrings::EMPTY_STATE_BODY); ?>
        <a href="https://github.com/wootsup/yt-builder-mcp/blob/main/docs/getting-started.md" target="_blank" rel="noopener noreferrer"><?php echo Text::_('COM_YTBMCP_GETTING_STARTED_GUIDE'); ?></a>
        &middot;
        <a href="<?php echo $esc(HtmlView::NPM_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo Text::_('COM_YTBMCP_NPM_PACKAGE'); ?></a>
    </p>
<?php else : ?>
    <table class="table table-striped">
        <thead>
        <tr>
            <th><?php echo Text::_('COM_YTBMCP_COL_LABEL'); ?></th>
            <th><?php echo Text::_('COM_YTBMCP_COL_KID'); ?></th>
            <th><?php echo Text::_('COM_YTBMCP_COL_SCOPE'); ?></th>
            <th><?php echo Text::_('COM_YTBMCP_COL_CREATED'); ?></th>
            <th><?php echo Text::_('COM_YTBMCP_COL_EXPIRES'); ?></th>
            <th><?php echo Text::_('COM_YTBMCP_COL_STATUS'); ?></th>
            <th><?php echo Text::_('COM_YTBMCP_COL_ACTIONS'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($this->keys as $kid => $meta) : ?>
            <?php
            $revokedAt = $meta['revoked_at'] ?? null;
            if ($revokedAt !== null) {
                $status = Text::sprintf('COM_YTBMCP_STATUS_REVOKED', HTMLHelper::_('date', (int) $revokedAt, 'Y-m-d H:i'));
            } else {
                $status = Text::_('COM_YTBMCP_STATUS_ACTIVE');
            }
            $expiresAt = $meta['expires_at'] ?? null;
            $expiresLabel = $expiresAt !== null
                ? HTMLHelper::_('date', (int) $expiresAt, 'Y-m-d H:i')
                : Text::_('COM_YTBMCP_NEVER');
            $label = (string) ($meta['label'] ?? '');
            $confirmPrompt = str_replace('<label>', $label, JoomlaBrandStrings::REVOKE_CONFIRMATION_PROMPT);
            ?>
            <tr>
                <td><?php echo $esc($label); ?></td>
                <td><code><?php echo $esc((string) $kid); ?></code></td>
                <td><?php echo $esc((string) ($meta['scope'] ?? '')); ?></td>
                <td><?php echo $esc(HTMLHelper::_('date', (int) ($meta['created_at'] ?? 0), 'Y-m-d H:i')); ?></td>
                <td><?php echo $esc($expiresLabel); ?></td>
                <td>
                    <?php if ($revokedAt !== null) : ?>
                        <span class="badge bg-secondary"><?php echo $esc($status); ?></span>
                    <?php else : ?>
                        <span class="badge bg-success"><?php echo $esc($status); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($revokedAt === null) : ?>
                        <form method="post" action="index.php?option=com_ytbmcp" class="d-inline"
                              onsubmit="return confirm(<?php echo $esc(json_encode($confirmPrompt)); ?>);">
                            <input type="hidden" name="task" value="dashboard.revokeKey">
                            <input type="hidden" name="kid" value="<?php echo $esc((string) $kid); ?>">
                            <?php echo HTMLHelper::_('form.token'); ?>
                            <button type="submit" class="btn btn-sm btn-danger"
                                    aria-label="<?php echo $esc(Text::sprintf('COM_YTBMCP_REVOKE_ARIA', $label)); ?>"><?php echo Text::_('COM_YTBMCP_REVOKE'); ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</div>
