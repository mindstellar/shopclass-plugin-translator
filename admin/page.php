<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use mindstellar\translator\admin\Export;
use mindstellar\translator\admin\Listing;
use mindstellar\translator\admin\Screen;
use mindstellar\translator\admin\PoUpload;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

if (osc_is_moderator()) {
    osc_admin_empty(['icon' => 'bi-shield-lock', 'title' => __('Only an administrator can change texts.', 'translator')]);

    return;
}

$trView   = Screen::view();
$trList   = $trView['listing'];
$trSource = $trView['source'];
$trState  = [
    'locale' => $trView['locale'],
    'source' => $trSource,
    'q'      => $trView['q'],
    'filter' => $trView['filter'],
    'page'   => $trView['page'],
];
$trHidden = Screen::hidden($trView);
$trJson = static fn (array $v): string => osc_esc_html((string) json_encode($v, JSON_UNESCAPED_UNICODE));
$trSrc  = $trSource->label() . ', ' . ($trView['locales'][$trView['locale']] ?? $trView['locale']);

osc_enqueue_script('admin-editor');
osc_admin_page_head(__('Translator', 'translator'));
?>
<div class="tr-page">
<p class="form-text mb-2"><?php echo osc_esc_html(__('Change or translate any text on your site. Your changes are kept when core, themes and plugins update.', 'translator')); ?></p>
<div id="tr-banner"><?php echo Screen::banner($trView); ?></div>
<div class="osc-toolbar osc-toolbar-between tr-toolbar">
    <form id="trControls" class="osc-toolbar-group tr-controls nocsrf" method="get" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>" nocsrf>
        <input type="hidden" name="page" value="plugins">
        <input type="hidden" name="action" value="renderplugin">
        <input type="hidden" name="route" value="<?php echo osc_esc_html(Screen::ROUTE); ?>">
        <div class="osc-filter tr-filter">
            <label class="visually-hidden" for="trLocale"><?php echo osc_esc_html(__('Language', 'translator')); ?></label>
            <select id="trLocale" name="locale" class="form-select form-select-sm">
                <?php foreach ($trView['locales'] as $trCode => $trName) { ?>
                    <option value="<?php echo osc_esc_html($trCode); ?>"<?php echo $trCode === $trView['locale'] ? ' selected' : ''; ?>><?php echo osc_esc_html($trName); ?></option>
                <?php } ?>
            </select>
            <label class="visually-hidden" for="trSource"><?php echo osc_esc_html(__('Source', 'translator')); ?></label>
            <select id="trSource" name="source" class="form-select form-select-sm">
                <?php foreach ($trView['groups'] as $trGroup => $trItems) { ?>
                    <optgroup label="<?php echo osc_esc_html($trGroup); ?>">
                        <?php foreach ($trItems as $trItem) { ?>
                            <option value="<?php echo osc_esc_html($trItem->name()); ?>"<?php echo $trItem->name() === $trSource->name() ? ' selected' : ''; ?>><?php echo osc_esc_html($trItem->label()); ?></option>
                        <?php } ?>
                    </optgroup>
                <?php } ?>
            </select>
            <label class="visually-hidden" for="trQ"><?php echo osc_esc_html(__('Search texts', 'translator')); ?></label>
            <input type="search" name="q" id="trQ" class="form-control form-control-sm tr-search" maxlength="<?php echo Listing::MAX_QUERY; ?>"
                   value="<?php echo osc_esc_html($trView['q']); ?>" placeholder="<?php echo osc_esc_html(__('Search texts', 'translator')); ?>">
            <button type="submit" class="btn btn-sm btn-primary" title="<?php echo osc_esc_html(__('Search', 'translator')); ?>"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden"><?php echo osc_esc_html(__('Search', 'translator')); ?></span></button>
        </div>
        <div class="osc-segmented" role="group" aria-label="<?php echo osc_esc_html(__('Filter', 'translator')); ?>">
            <?php foreach ([
                'all'     => __('All', 'translator'),
                'changed' => __('Changed only', 'translator'),
                'look'    => __('Needs a look', 'translator'),
            ] as $trKey => $trWord) { ?>
                <input type="radio" name="filter" id="trFilter-<?php echo $trKey; ?>" value="<?php echo $trKey; ?>"<?php echo $trKey === $trView['filter'] ? ' checked' : ''; ?>>
                <label for="trFilter-<?php echo $trKey; ?>"><?php echo osc_esc_html($trWord) . osc_admin_count((int) $trView['counts'][$trKey]); ?></label>
            <?php } ?>
        </div>
    </form>
    <button type="button" class="btn btn-sm btn-dim" id="trIoOpen" aria-haspopup="dialog" aria-controls="trIo"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> <?php echo osc_esc_html(__('Import and export', 'translator')); ?></button>
</div>
<table class="table tr-table">
    <thead>
    <tr>
        <th><?php echo osc_esc_html(__('Original text', 'translator')); ?></th>
        <th><?php echo osc_esc_html(__('Your text', 'translator')); ?></th>
        <th><?php echo osc_esc_html(__('Status', 'translator')); ?></th>
        <th><span class="visually-hidden"><?php echo osc_esc_html(__('Actions', 'translator')); ?></span></th>
    </tr>
    </thead>
    <tbody>
    <?php if ($trList['rows'] === []) {
        if ($trView['q'] !== '') {
            $trEmpty = [
                'icon'   => 'bi-search',
                'title'  => __('No texts match your search.', 'translator'),
                'text'   => __('Check the spelling, or search for a shorter part of the text.', 'translator'),
                'action' => ['label' => __('Clear search', 'translator'), 'url' => Screen::url(['q' => '', 'page' => 1] + $trState)],
            ];
        } elseif ($trView['filter'] === 'look') {
            $trEmpty = ['icon' => 'bi-check2-circle', 'title' => __('No texts need a look.', 'translator'), 'text' => __('Your texts match the current originals.', 'translator')];
        } elseif ($trView['filter'] === 'changed') {
            $trEmpty = ['icon' => 'bi-pencil', 'title' => __('You have not changed any texts here yet.', 'translator'), 'text' => __('Pick a text in All to change it. Your changes show here.', 'translator')];
        } else {
            $trEmpty = ['icon' => 'bi-inbox', 'title' => __('No texts match.', 'translator')];
        }
        osc_admin_table_empty(4, $trEmpty);
    }
foreach ($trList['rows'] as $trRow) {
    $trFlags   = (array) $trRow['flags'];
    $trRemoved = in_array('removed', $trFlags, true);
    $trNewer   = in_array('newer', $trFlags, true);
    $trBad     = in_array('placeholders', $trFlags, true);
    $trOriginal = (string) $trRow['original'];
    if ($trRow['plural'] !== null && $trRow['plural'] !== '') {
        $trOriginal .= ' / ' . $trRow['plural'];
    }
    if ($trRemoved) { ?>
        <tr class="tr-row tr-row-static">
    <?php } else { ?>
        <tr class="tr-row" tabindex="0"
            data-context="<?php echo osc_esc_html((string) $trRow['context']); ?>"
            data-original="<?php echo osc_esc_html((string) $trRow['original']); ?>"
            data-plural="<?php echo osc_esc_html((string) ($trRow['plural'] ?? '')); ?>"
            data-text="<?php echo $trRow['text'] === null ? '' : $trJson($trRow['text']); ?>"
            data-shipped="<?php echo $trJson((array) $trRow['shipped']); ?>"<?php
        if ($trFlags !== []) { ?> data-flags="<?php echo osc_esc_html(implode(' ', $trFlags)); ?>"<?php }
        if ($trNewer && $trRow['now'] !== []) { ?> data-newer="<?php echo $trJson((array) $trRow['now']); ?>"<?php } ?>>
    <?php } ?>
            <td data-col-name="<?php echo osc_esc_html(__('Original text', 'translator')); ?>"><span class="tr-orig"><?php echo osc_esc_html($trOriginal); ?></span><?php
    if ($trRow['context'] !== '') { ?> <span class="tr-src"><?php echo osc_esc_html((string) $trRow['context']); ?></span><?php } ?></td>
            <td data-col-name="<?php echo osc_esc_html(__('Your text', 'translator')); ?>"><?php
    if ($trRow['text'] !== null) {
        echo osc_esc_html(implode(' / ', $trRow['text']));
    } else { ?><span class="tr-mine-empty"><?php echo osc_esc_html(implode(' / ', (array) $trRow['shipped'])); ?></span><?php }
    if ($trNewer) { ?><span class="tr-diff"><?php echo strtr(osc_esc_html(__('You replaced %1$s. It is now %2$s.', 'translator')), [
        '%1$s' => '<del>' . osc_esc_html(implode(' / ', (array) ($trRow['replaced'] ?? []))) . '</del>',
        '%2$s' => '<ins>' . osc_esc_html(implode(' / ', (array) $trRow['now'])) . '</ins>',
    ]); ?></span><?php }
    if ($trBad) { ?><span class="tr-diff tr-diff-bad"><?php echo implode('<br>', array_map(static fn ($l): string => osc_esc_html((string) $l), (array) $trRow['problems'])); ?></span><?php }
    if ($trFlags !== []) { ?><div class="tr-row-actions"><form method="post" action="<?php echo osc_esc_html($trView['urls']['post']); ?>"><?php
        foreach ($trHidden + ['context' => (string) $trRow['context'], 'original' => (string) $trRow['original']] as $trName => $trValue) { ?><input type="hidden" name="<?php echo osc_esc_html($trName); ?>" value="<?php echo osc_esc_html($trValue); ?>"><?php }
        if ($trRemoved) { ?><button type="submit" name="<?php echo Screen::ACTION; ?>" value="reset" class="btn btn-sm btn-dim"><?php echo osc_esc_html(__('Remove mine', 'translator')); ?></button><?php
        } elseif ($trBad) { ?><button type="submit" name="<?php echo Screen::ACTION; ?>" value="reset" class="btn btn-sm btn-dim"><?php echo osc_esc_html(__('Use the original', 'translator')); ?></button><?php
        } else { ?><button type="submit" name="<?php echo Screen::ACTION; ?>" value="keep" class="btn btn-sm btn-dim"><?php echo osc_esc_html(__('Keep mine', 'translator')); ?></button><button type="submit" name="<?php echo Screen::ACTION; ?>" value="reset" class="btn btn-sm btn-dim"><?php echo osc_esc_html(__('Use the new original', 'translator')); ?></button><?php } ?></form><?php
        if (!$trRemoved) { ?><button type="button" class="btn btn-sm btn-primary tr-edit"><?php echo osc_esc_html(__('Edit', 'translator')); ?></button><?php } ?></div><?php } ?></td>
            <td data-col-name="<?php echo osc_esc_html(__('Status', 'translator')); ?>"><?php
    if ($trRemoved) {
        osc_admin_status('failed', __('Original removed', 'translator'));
    } elseif ($trBad) {
        osc_admin_status('failed', __('Placeholders differ', 'translator'));
    } elseif ($trNewer) {
        osc_admin_status('pending', __('Original changed', 'translator'));
    } elseif ($trRow['status'] === 'changed') {
        osc_admin_status('active', __('Changed', 'translator'));
    } ?></td>
            <td class="text-end tr-actions"><?php if ($trFlags === []) { ?><button type="button" class="btn btn-sm btn-dim" tabindex="-1"><i class="bi bi-pencil" aria-hidden="true"></i> <?php echo osc_esc_html(__('Edit', 'translator')); ?></button><?php } ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php
osc_admin_pager([
    'total'    => $trList['total'],
    'per_page' => Listing::PER_PAGE,
    'page'     => $trList['page'],
    'base_url' => Screen::url($trState),
]);
?>

<div class="osc-drawer-backdrop" id="trIoBackdrop" hidden></div>
<div class="osc-drawer tr-io" id="trIo" role="dialog" aria-modal="true" aria-labelledby="trIoTitle" hidden>
    <header class="osc-drawer-head">
        <div>
            <span class="tr-src"><?php echo osc_esc_html($trSrc); ?></span>
            <h2 class="osc-drawer-title" id="trIoTitle"><?php echo osc_esc_html(__('Import and export', 'translator')); ?></h2>
        </div>
        <button type="button" class="osc-drawer-close" data-tr-close aria-label="<?php echo osc_esc_html(__('Close', 'translator')); ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </header>
    <div class="osc-drawer-body">
        <section class="tr-io-part" aria-labelledby="trIoUp">
            <h3 class="tr-io-title" id="trIoUp"><?php echo osc_esc_html(__('Upload a .po file', 'translator')); ?></h3>
            <?php osc_admin_form_open([
                'url'        => osc_route_admin_url(Screen::ROUTE),
                'page'       => 'plugins',
                'action'     => 'renderplugin',
                'upload'     => true,
                'horizontal' => false,
                'fields'     => [
                    'route'         => Screen::ROUTE,
                    'locale'        => $trView['locale'],
                    'source'        => $trSource->name(),
                    'q'             => $trView['q'],
                    'filter'        => $trView['filter'],
                    'iPage'         => (string) $trView['page'],
                    'MAX_FILE_SIZE' => (string) PoUpload::MAX_BYTES,
                ],
            ]); ?>
            <label class="visually-hidden" for="trFile"><?php echo osc_esc_html(__('Upload a .po file', 'translator')); ?></label>
            <input type="file" name="file" accept=".po,text/x-gettext-translation" class="form-control form-control-sm" id="trFile" required aria-describedby="trFileNote">
            <fieldset class="tr-modes">
                <legend class="tr-label"><?php echo osc_esc_html(__('What to do with your changes', 'translator')); ?></legend>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" value="add" id="trModeAdd" checked>
                    <label class="form-check-label" for="trModeAdd"><?php echo osc_esc_html(__('Add to my changes', 'translator')); ?></label>
                    <span class="tr-note d-block mt-0"><?php echo osc_esc_html(__('Texts in the file replace the same texts. Your other changes stay.', 'translator')); ?></span>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" value="replace" id="trModeReplace">
                    <label class="form-check-label" for="trModeReplace"><?php echo osc_esc_html(__('Replace all my changes', 'translator')); ?></label>
                    <span class="tr-note d-block mt-0"><?php echo osc_esc_html(__('Only the texts in the file are kept.', 'translator')); ?></span>
                </div>
            </fieldset>
            <button type="submit" name="<?php echo Screen::ACTION; ?>" value="upload" class="btn btn-sm btn-primary"><i class="bi bi-upload" aria-hidden="true"></i> <?php echo osc_esc_html(__('Upload', 'translator')); ?></button>
            <p class="tr-note mb-0" id="trFileNote"><?php echo osc_esc_html(__('Max 2 MB, for this language and source. You can undo an upload once.', 'translator')); ?></p>
            <?php osc_admin_form_close(null, ['horizontal' => false]); ?>
            <?php if ($trView['hasBackup']) { ?>
                <form method="post" action="<?php echo osc_esc_html($trView['urls']['post']); ?>" class="tr-undo"><?php
                foreach ($trHidden as $trName => $trValue) { ?><input type="hidden" name="<?php echo osc_esc_html($trName); ?>" value="<?php echo osc_esc_html($trValue); ?>"><?php } ?>
                    <button type="submit" name="<?php echo Screen::ACTION; ?>" value="undo" class="btn btn-sm btn-dim"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> <?php echo osc_esc_html(__('Undo last upload', 'translator')); ?></button>
                    <p class="tr-note mb-0"><?php echo osc_esc_html(__('Puts your texts back as they were before the last upload or Remove all. Texts saved since then are lost.', 'translator')); ?></p>
                </form>
            <?php } ?>
        </section>

        <section class="tr-io-part" aria-labelledby="trIoDown">
            <h3 class="tr-io-title" id="trIoDown"><?php echo osc_esc_html(__('Download', 'translator')); ?></h3>
            <?php if ($trView['hasCustom']) { ?>
                <div class="tr-file-actions">
                    <a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html($trView['urls']['download']); ?>"><i class="bi bi-download" aria-hidden="true"></i> <?php echo osc_esc_html(__('Download my changes (.po)', 'translator')); ?></a>
                </div>
                <p class="tr-note"><?php echo osc_esc_html(__('Only your changed texts for this language and source.', 'translator')); ?></p>
            <?php } else { ?>
                <p class="tr-note mt-0"><?php echo osc_esc_html(__('You have not changed any texts here yet.', 'translator')); ?></p>
            <?php } ?>
            <div class="tr-file-actions"><?php echo Export::links($trState); ?></div>
            <p class="tr-note mb-0"><?php echo osc_esc_html(__('The full translation has the shipped texts with your changes over them.', 'translator')); ?></p>
        </section>

        <?php if ($trView['hasCustom']) { ?>
            <section class="tr-io-part tr-io-danger" aria-labelledby="trIoRemove">
                <h3 class="tr-io-title" id="trIoRemove"><?php echo osc_esc_html(__('Remove all my changes', 'translator')); ?></h3>
                <p class="tr-note mt-0"><?php echo osc_esc_html(sprintf(__('All your changed texts for %s go back to the original. You can undo this once.', 'translator'), $trSrc)); ?></p>
                <form method="post" action="<?php echo osc_esc_html($trView['urls']['post']); ?>"><?php
                foreach ($trHidden as $trName => $trValue) { ?><input type="hidden" name="<?php echo osc_esc_html($trName); ?>" value="<?php echo osc_esc_html($trValue); ?>"><?php } ?>
                    <button type="submit" name="<?php echo Screen::ACTION; ?>" value="removeall" class="btn btn-sm osc-btn-danger"
                            data-osc-confirm="<?php echo osc_esc_html(sprintf(__('All your changed texts for %s go back to the original. You can undo this once.', 'translator'), $trSrc)); ?>"
                            data-osc-confirm-title="<?php echo osc_esc_html(__('Remove all my changes?', 'translator')); ?>"
                            data-osc-confirm-label="<?php echo osc_esc_html(__('Remove all', 'translator')); ?>"
                            data-osc-confirm-cancel="<?php echo osc_esc_html(__('Cancel', 'translator')); ?>"><i class="bi bi-trash" aria-hidden="true"></i> <?php echo osc_esc_html(__('Remove all my changes', 'translator')); ?></button>
                </form>
            </section>
        <?php } ?>

        <p class="tr-note tr-io-where"><?php printf(osc_esc_html(__('Deleting a language does not delete your changes. They are in %s.', 'translator')), '<code>oc-content/plugin-data/translator/</code>'); ?></p>
    </div>
    <div class="osc-drawer-foot">
        <div class="osc-drawer-actions">
            <button type="button" class="btn btn-sm btn-dim" data-tr-close><?php echo osc_esc_html(__('Close', 'translator')); ?></button>
        </div>
    </div>
</div>

<div class="osc-drawer-backdrop" id="trBackdrop" hidden></div>
<div class="osc-drawer" id="trDrawer" role="dialog" aria-modal="true" aria-labelledby="trTitle" hidden
     data-src="<?php echo osc_esc_html($trSrc); ?>"
    <?php foreach ($trView['messages'] as $trMsgKey => $trMsg) { ?>
     data-msg-<?php echo $trMsgKey; ?>="<?php echo osc_esc_html($trMsg); ?>"
    <?php } ?>>
    <header class="osc-drawer-head">
        <div>
            <span class="tr-src" id="trSrc"><?php echo osc_esc_html($trSrc); ?></span>
            <h2 class="osc-drawer-title" id="trTitle"><?php echo osc_esc_html(__('Edit text', 'translator')); ?></h2>
        </div>
        <button type="button" class="osc-drawer-close" id="trClose" aria-label="<?php echo osc_esc_html(__('Close', 'translator')); ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </header>
    <div class="osc-drawer-body">
        <form id="trForm" method="post" action="<?php echo osc_esc_html($trView['urls']['post']); ?>">
            <?php foreach ($trHidden as $trName => $trValue) { ?>
                <input type="hidden" name="<?php echo osc_esc_html($trName); ?>" value="<?php echo osc_esc_html($trValue); ?>">
            <?php } ?>
            <input type="hidden" name="context" value="">
            <input type="hidden" name="original" value="">
            <span class="tr-label"><?php echo osc_esc_html(__('Original text', 'translator')); ?></span>
            <div class="tr-original-box mb-3" id="trOrig"></div>
            <div id="trNewOrig" class="mb-3" hidden>
                <span class="tr-label"><?php echo osc_esc_html(__('The text you replaced is now', 'translator')); ?></span>
                <div class="tr-original-box" id="trNewOrigText"></div>
            </div>
            <div id="trForms"></div>
            <div id="trCheck" class="tr-ok" role="status"></div>
        </form>
    </div>
    <div class="osc-drawer-foot">
        <div class="osc-drawer-actions">
            <button type="submit" form="trForm" name="<?php echo Screen::ACTION; ?>" value="save" class="btn btn-sm btn-primary" id="trSave"><?php echo osc_esc_html(__('Save', 'translator')); ?></button>
            <button type="submit" form="trForm" name="<?php echo Screen::ACTION; ?>" value="reset" class="btn btn-sm btn-dim" id="trReset"><?php echo osc_esc_html(__('Reset to original', 'translator')); ?></button>
            <button type="button" class="btn btn-sm btn-dim" id="trCancel"><?php echo osc_esc_html(__('Cancel', 'translator')); ?></button>
        </div>
    </div>
</div>
</div>
