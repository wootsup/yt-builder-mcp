<?php
/**
 * Dashboard — Diagnostics tab partial (native Joomla/Bootstrap, Wave-11).
 *
 * Versions / Security / REST surface cards + endpoint list + "Copy
 * diagnostics as Markdown" button. Parity with WP
 * SettingsPage::render_diagnostics_tab(), rendered with native Bootstrap
 * cards / tables (no custom `.ytb-*` colour classes, no inline style="").
 *
 * @var \WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView $this
 *
 * @license   GPL-2.0-or-later
 * @copyright (C) 2026 getimo productions
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use WootsUp\BuilderMcp\Platform\Joomla\Settings\JoomlaBrandStrings;

/** @var \WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView $this */
/** @var callable(string):string $esc */
$esc = $esc ?? static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<div class="p-3">

<p class="text-muted"><?php echo $esc(JoomlaBrandStrings::DIAGNOSTICS_INTRO); ?></p>

<p class="mb-4">
    <button type="button" class="btn btn-primary" id="ytb-mcp-diag-copy"
            data-ytb-copy="ytb-mcp-diag-md" data-ytb-copy-status="ytb-mcp-diag-status"><?php echo Text::_('COM_YTBMCP_COPY_DIAGNOSTICS'); ?></button>
    <span id="ytb-mcp-diag-status" class="ms-2 fw-bold" aria-live="polite"></span>
    <textarea id="ytb-mcp-diag-md" class="visually-hidden" readonly aria-hidden="true"><?php echo $esc($this->diagnosticsMarkdown); ?></textarea>
</p>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h3 class="card-title h6 text-uppercase text-muted"><?php echo Text::_('COM_YTBMCP_DIAG_VERSIONS'); ?></h3>
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr><th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_PLUGIN'); ?></th><td class="font-monospace"><?php echo $esc($this->pluginVersion); ?></td></tr>
                    <tr><th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_YOOTHEME'); ?></th><td class="font-monospace"><?php echo $esc($this->ytVersion ?? '—'); ?></td></tr>
                    <tr><th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_JOOMLA'); ?></th><td class="font-monospace"><?php echo $esc($this->cmsVersion ?? '—'); ?></td></tr>
                    <tr><th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_PHP'); ?></th><td class="font-monospace"><?php echo $esc($this->phpVersion); ?></td></tr>
                    <tr><th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_SCHEMA'); ?></th><td class="font-monospace"><?php echo $esc((string) $this->schemaVersion); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h3 class="card-title h6 text-uppercase text-muted"><?php echo Text::_('COM_YTBMCP_DIAG_SECURITY'); ?></h3>
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr>
                        <th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_SIGNING_SECRET'); ?></th>
                        <td><?php echo $this->signingSecretPresent
                            ? Text::_('COM_YTBMCP_DIAG_SIGNING_PRESENT')
                            : Text::_('COM_YTBMCP_DIAG_SIGNING_MISSING'); ?></td>
                    </tr>
                    <tr><th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_BEARER_KEYS'); ?></th><td class="font-monospace"><?php echo $esc((string) $this->bearerKeyCount); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h3 class="card-title h6 text-uppercase text-muted"><?php echo Text::_('COM_YTBMCP_DIAG_REST_SURFACE'); ?></h3>
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr><th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_ENDPOINTS'); ?></th><td class="font-monospace"><?php echo $esc((string) count($this->endpoints)); ?></td></tr>
                    <tr>
                        <th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_PROBE_URL'); ?></th>
                        <td class="text-break"><a href="<?php echo $esc($this->healthUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo $esc($this->healthUrl); ?></a></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_IDENTITY_URL'); ?></th>
                        <td class="text-break"><a href="<?php echo $esc($this->identityUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo $esc($this->identityUrl); ?></a></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($this->endpoints !== []) : ?>
    <h3 class="h5 mt-4"><?php echo Text::_('COM_YTBMCP_DIAG_REGISTERED_ENDPOINTS'); ?></h3>
    <ul class="list-unstyled font-monospace small">
        <?php foreach ($this->endpoints as $endpoint) : ?>
            <li><?php echo $esc($endpoint); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

</div>
