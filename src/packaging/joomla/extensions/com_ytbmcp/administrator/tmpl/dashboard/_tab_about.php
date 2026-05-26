<?php
/**
 * Dashboard — About tab partial (native Joomla/Bootstrap, Wave-11).
 *
 * MCP intro, npx setup command, supported-client list, version/license
 * table, repo/npm links. Parity with WP SettingsPage::render_about_tab(),
 * rendered with native Bootstrap (no custom `.ytb-*` colour classes,
 * no inline style="").
 *
 * @var \WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView $this
 *
 * @license   GPL-2.0-or-later
 * @copyright (C) 2026 getimo productions
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView;

/** @var HtmlView $this */
/** @var callable(string):string $esc */
$esc = $esc ?? static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$clients = [
    'Claude Desktop',
    'Claude Code',
    'Cursor',
    'Zed',
    'Continue',
    'Cline',
    'Roo Code',
    'Codex CLI',
    'Gemini CLI',
];
?>
<div class="p-3">

<h2 class="h4"><?php echo Text::_('COM_YTBMCP_ABOUT_HEADING'); ?></h2>

<p><?php echo Text::_('COM_YTBMCP_ABOUT_INTRO_1'); ?></p>
<p><?php echo Text::_('COM_YTBMCP_ABOUT_INTRO_2'); ?></p>

<h3 class="h5 mt-4"><?php echo Text::_('COM_YTBMCP_ABOUT_CONNECT'); ?></h3>
<p><?php echo Text::_('COM_YTBMCP_ABOUT_CONNECT_HINT'); ?></p>
<pre class="bg-dark text-light p-3 rounded"><code>npx -y @wootsup/yt-builder-mcp setup</code></pre>

<h3 class="h5 mt-4"><?php echo Text::_('COM_YTBMCP_ABOUT_CLIENTS'); ?></h3>
<p class="text-muted small"><?php echo Text::_('COM_YTBMCP_ABOUT_CLIENTS_HINT'); ?></p>
<div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($clients as $client) : ?>
        <span class="badge bg-secondary"><?php echo $esc($client); ?></span>
    <?php endforeach; ?>
</div>

<h3 class="h5 mt-4"><?php echo Text::_('COM_YTBMCP_ABOUT_VERSION_LICENSE'); ?></h3>
<table class="table table-striped">
    <tbody>
    <tr>
        <th scope="row"><?php echo Text::_('COM_YTBMCP_DIAG_PLUGIN'); ?></th>
        <td><code>v<?php echo $esc($this->pluginVersion); ?></code> &middot; GPL-2.0-or-later</td>
    </tr>
    <tr>
        <th scope="row"><?php echo Text::_('COM_YTBMCP_ABOUT_MCP_SERVER'); ?></th>
        <td><code>@wootsup/yt-builder-mcp@<?php echo $esc($this->pluginVersion); ?></code> &middot; MIT</td>
    </tr>
    <tr>
        <th scope="row"><?php echo Text::_('COM_YTBMCP_ABOUT_REPOSITORY'); ?></th>
        <td><a href="<?php echo $esc(HtmlView::REPO_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo $esc(HtmlView::REPO_URL); ?></a></td>
    </tr>
    <tr>
        <th scope="row"><?php echo Text::_('COM_YTBMCP_NPM_PACKAGE'); ?></th>
        <td><a href="<?php echo $esc(HtmlView::NPM_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo $esc(HtmlView::NPM_URL); ?></a></td>
    </tr>
    </tbody>
</table>

</div>
