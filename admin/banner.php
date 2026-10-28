<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use mindstellar\translator\admin\Screen;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/** @var array $trSync the stored scan, @var string $trAgo when it ran, @var array $trLabels, @var array $trFields the hidden fields */
$trTotal = (int) $trSync['total'];
?>
<div class="alert <?php echo $trTotal > 0 ? 'alert-warning' : 'alert-success'; ?> tr-sync" role="status">
<i class="bi <?php echo $trTotal > 0 ? 'bi-exclamation-triangle' : 'bi-check-circle'; ?>" aria-hidden="true"></i>
<?php if ($trTotal > 0) { ?>
<span class="tr-sync-text"><?php echo osc_esc_html(sprintf(_n('%d of your texts needs a look.', '%d of your texts need a look.', $trTotal, 'translator'), $trTotal)); ?></span>
<span class="tr-sync-list"><?php foreach ($trSync['items'] as $trPair => $trItem) {
    $trUrl = Screen::url(['locale' => (string) $trItem['locale'], 'source' => (string) $trItem['source'], 'filter' => 'look', 'page' => 1]);
    ?><a href="<?php echo osc_esc_html($trUrl); ?>"><?php echo osc_esc_html((string) ($trLabels[$trPair] ?? $trPair) . ' (' . (int) $trItem['total'] . ')'); ?></a> <?php
} ?></span>
<?php } else { ?>
<span class="tr-sync-text"><?php echo osc_esc_html(__('All your texts match the current originals.', 'translator')); ?></span>
<?php } ?>
<span class="tr-sync-meta"><?php echo osc_esc_html(sprintf(__('Last checked %s', 'translator'), $trAgo)); ?></span>
<form method="post" action="<?php echo osc_esc_html(osc_route_admin_url(Screen::ROUTE)); ?>" class="tr-sync-check">
<?php foreach ($trFields as $trName => $trValue) { ?>
<input type="hidden" name="<?php echo osc_esc_html($trName); ?>" value="<?php echo osc_esc_html($trValue); ?>">
<?php } ?>
<button type="submit" name="<?php echo Screen::ACTION; ?>" value="check" class="btn btn-sm btn-dim"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> <?php echo osc_esc_html(__('Check again', 'translator')); ?></button>
</form>
</div>
