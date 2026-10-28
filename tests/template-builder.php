<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use Gettext\Translations;
use mindstellar\translator\TemplateBuilder;

$fixture = realpath(__DIR__ . '/fixtures/template');

harness_section('scan');
$t     = TemplateBuilder::scan($fixture, 'tplfix');
$texts = [];
foreach ($t as $entry) {
    $texts[] = $entry->getOriginal();
}
sort($texts);
pin('only literal texts with the domain are kept', [
    '%d item',
    'Fully qualified',
    'Hello',
    "It's \"quoted\" and a \\ backslash",
    "Line one\nLine two\t\"tab\"",
    'Post',
], $texts);
check('another domain is skipped', $t->find('', 'Other domain') === false);
check('a call with no domain is core, so skipped', $t->find('', 'Core text') === false);
check('a joined text is skipped', $t->find('', 'Joined ') === false);
check('a variable domain is skipped', $t->find('', 'Domain is a variable') === false);
check('methods and static calls are skipped', $t->find('', 'Method call') === false && $t->find('', 'Static call') === false);
check('vendor/ and tests/ are skipped', $t->find('', 'From vendor') === false && $t->find('', 'From tests') === false);
pin('_n keeps the plural', '%d items', $t->find('', '%d item') ? $t->find('', '%d item')->getPlural() : null);
pin('_x keeps the context', 'Post', $t->find('verb', 'Post') ? $t->find('verb', 'Post')->getOriginal() : null);
$refs = $t->find('', 'Hello') ? $t->find('', 'Hello')->getReferences() : [];
pin('references are relative file and line', [['inc/escapes.php', 4], ['index.php', 5]], array_values($refs));
pin('another domain gives nothing', 0, count(TemplateBuilder::scan($fixture, 'nothing-here')));
pin('a missing folder gives nothing', 0, count(TemplateBuilder::scan($fixture . '/no-such-dir', 'tplfix')));

harness_section('files');
pin('PHP files only, skip folders left out', ['inc/escapes.php', 'index.php'], TemplateBuilder::files($fixture));
$outside = tmpdir();
file_put_contents($outside . '/secret.php', "<?php echo __('Outside', 'tplfix');");
mkdir($outside . '/dir');
file_put_contents($outside . '/dir/more.php', "<?php echo __('Outside dir', 'tplfix');");
$linked = tmpdir();
copy($fixture . '/index.php', $linked . '/index.php');
symlink($outside . '/secret.php', $linked . '/secret.php');
symlink($outside . '/dir', $linked . '/dir');
pin('symlinks leaving the folder are not read', ['index.php'], TemplateBuilder::files($linked));
check('their texts are not in the template', TemplateBuilder::scan($linked, 'tplfix')->find('', 'Outside') === false);

harness_section('build');
$target = tmpdir() . '/templates/plugin-tplfix.pot';
pin('build returns the count', 6, TemplateBuilder::build($fixture, 'tplfix', $target));
$pot = (string) file_get_contents($target);
check('the header says UTF-8', strpos($pot, '"Content-Type: text/plain; charset=UTF-8\n"') !== false);
check('quotes and backslashes are escaped', strpos($pot, 'msgid "It\'s \"quoted\" and a \\\\ backslash"') !== false, $pot);
check('a newline is escaped', strpos($pot, '"Line one\n"') !== false && strpos($pot, '"Line two\t\"tab\""') !== false, $pot);
check('the plural has msgid_plural', strpos($pot, 'msgid_plural "%d items"') !== false);
check('references are written', strpos($pot, '#: inc/escapes.php:4') !== false);
$back = Translations::fromPoFile($target);
pin('the file reads back with every text', 6, count($back));
pin('escapes survive a read back', "Line one\nLine two\t\"tab\"", $back->find('', "Line one\nLine two\t\"tab\"") ? $back->find('', "Line one\nLine two\t\"tab\"")->getOriginal() : null);
$none = dirname($target) . '/plugin-none.pot';
pin('no texts gives 0', 0, TemplateBuilder::build($fixture, 'nothing-here', $none));
check('and writes no file', !is_file($none));

harness_section('caps');
$big = tmpdir();
file_put_contents($big . '/big.php', "<?php echo __('Big', 'tplfix');\n" . str_repeat('// pad' . "\n", (int) (TemplateBuilder::MAX_FILE_BYTES / 7) + 10));
pin('a file over the size cap is skipped', [], TemplateBuilder::files($big));

exit(harness_result());
