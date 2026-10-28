<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\CustomStore;
use mindstellar\translator\Sources;
use mindstellar\translator\SyncStatus;

function copyTree(string $from, string $to): void
{
    mkdir($to, 0755, true);
    foreach (scandir($from) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        is_dir("$from/$name") ? copyTree("$from/$name", "$to/$name") : copy("$from/$name", "$to/$name");
    }
}

$site = tmpdir() . '/site';
copyTree(__DIR__ . '/fixtures/site', $site);
$lang = $site . '/oc-content/languages/';
$dir  = $site . '/oc-content/plugin-data/translator/';

$sources = new Sources($lang, $site . '/oc-content/themes/', $site . '/oc-content/plugins/', 'demo', ['alpha/index.php', 'beta/index.php']);
$store   = new CustomStore($dir);
$core    = $sources->find('core');
$forms   = 'nplurals=2; plural=(n != 1);';

$GLOBALS['kv']     = null;
$GLOBALS['writes'] = 0;
$read  = static fn (): ?string => $GLOBALS['kv'];
$write = static function (?string $json): void {
    $GLOBALS['kv'] = $json;
    $GLOBALS['writes']++;
};
$mk = static fn (): SyncStatus => new SyncStatus($sources, $store, $dir, ['hi_IN', 'en_US'], $read, $write);

$store->set('hi_IN', $core, ['context' => '', 'original' => "Seller's information", 'plural' => null, 'text' => ['मेरा'], 'replaced' => ['old'], 'references' => []], $forms);
$store->set('hi_IN', $core, ['context' => '', 'original' => 'Nope', 'plural' => null, 'text' => ['Nein'], 'replaced' => [], 'references' => []], $forms);
$po = $store->poPath('hi_IN', $core);

harness_section('pairs');
pin('one pair', ['hi_IN/core'], array_keys($mk()->pairs()));
foreach (['fr_FR/core.po', 'hi_IN/plugin-beta.po', 'hi_IN/evil.po', 'hi_IN/x.txt'] as $name) {
    @mkdir(dirname($dir . $name), 0755, true);
    copy($po, $dir . $name);
}
pin('uninstalled locale, unlisted sources and other files are ignored', ['hi_IN/core'], array_keys($mk()->pairs()));

harness_section('run');
$run = $mk()->run();
pin('counts', ['locale' => 'hi_IN', 'source' => 'core', 'removed' => 1, 'newer' => 1, 'placeholders' => 0, 'total' => 2], $run['items']['hi_IN/core']);
pin('total', 2, $run['total']);
check('at is now', abs($run['at'] - time()) <= 5);
check('fingerprint is sha1', preg_match('/^[0-9a-f]{40}$/', $run['fingerprint']) === 1);
pin('run() does not store', 0, $GLOBALS['writes']);

harness_section('current');
$first = $mk()->current();
pin('first call stores', 1, $GLOBALS['writes']);
sleep(1);
$second = $mk()->current();
pin('second call reads the store', 1, $GLOBALS['writes']);
pin('same result', $first['at'], $second['at']);
touch($po, time() + 2);
$mk()->current();
pin('a changed custom file rescans', 2, $GLOBALS['writes']);
$mk()->current(true);
pin('force rescans', 3, $GLOBALS['writes']);
touch($lang . 'core.pot', time() + 5);
$mk()->current();
pin('a changed source file rescans', 4, $GLOBALS['writes']);
$before = $mk()->fingerprint();
touch($lang . 'hi_IN/core.po', time() + 9);
check('a changed shipped translation changes the fingerprint', $mk()->fingerprint() !== $before);
$mk()->current();
pin('and rescans', 5, $GLOBALS['writes']);

harness_section('bad store');
foreach (['{', 'null', '{"fingerprint":"x"}'] as $bad) {
    $GLOBALS['kv'] = $bad;
    $before        = $GLOBALS['writes'];
    $mk()->current();
    pin('rescans over ' . $bad, $before + 1, $GLOBALS['writes']);
    check('store rewritten after ' . $bad, $GLOBALS['kv'] !== $bad);
}

harness_section('bad store with the right fingerprint');
$good = $mk()->run();
$cases = [
    'at as text'        => ['at' => '1'] + $good,
    'total as text'     => ['total' => '2'] + $good,
    'items as text'     => ['items' => 'x'] + $good,
    'no items'          => array_diff_key($good, ['items' => 1]),
    'fingerprint as int' => ['fingerprint' => 5] + $good,
];
foreach ($cases as $name => $data) {
    $GLOBALS['kv'] = json_encode($data);
    $before        = $GLOBALS['writes'];
    $mk()->current();
    pin('rescans over ' . $name, $before + 1, $GLOBALS['writes']);
}
$goodItem = $good['items']['hi_IN/core'];
$itemCases = [
    'item as int'       => ['hi_IN/core' => 5],
    'item without total' => ['hi_IN/core' => array_diff_key($goodItem, ['total' => 1])],
    'count as text'     => ['hi_IN/core' => ['newer' => '1'] + $goodItem],
    'negative count'    => ['hi_IN/core' => ['newer' => -1] + $goodItem],
    'path-like key'     => ['../x/core' => ['locale' => '..', 'source' => 'core'] + $goodItem],
    'key not matching'  => ['hi_IN/messages' => $goodItem],
];
foreach ($itemCases as $name => $items) {
    $GLOBALS['kv'] = json_encode(['items' => $items] + $good);
    $before        = $GLOBALS['writes'];
    $mk()->current();
    pin('rescans over ' . $name, $before + 1, $GLOBALS['writes']);
    check('rewritten after ' . $name, json_decode($GLOBALS['kv'], true)['items'] === $good['items']);
}
$GLOBALS['kv'] = json_encode($good);
$before        = $GLOBALS['writes'];
$mk()->current();
pin('a well-formed stored result is reused', $before, $GLOBALS['writes']);

harness_section('pairs are read once');
$one = $mk();
$one->fingerprint();
$store->set('hi_IN', $sources->find('plugin-alpha'), ['context' => '', 'original' => 'Nope', 'plural' => null, 'text' => ['Nein'], 'replaced' => [], 'references' => []], $forms);
pin('run() reuses the pair list from fingerprint()', ['hi_IN/core'], array_keys($one->pairs()));
pin('a new instance sees the new file', ['hi_IN/core', 'hi_IN/plugin-alpha'], array_keys($mk()->pairs()));
unlink($store->poPath('hi_IN', $sources->find('plugin-alpha')));

harness_section('another file and forget');
$clean = ['context' => '', 'original' => 'Contact the seller', 'plural' => null, 'text' => ['मेरा'], 'replaced' => ['विक्रेता से संपर्क करें'], 'references' => []];
$store->set('hi_IN', $sources->find('messages'), $clean, $forms);
pin('two pairs', ['hi_IN/core', 'hi_IN/messages'], array_keys($mk()->pairs()));
$items = array_keys($mk()->run()['items']);
pin('clean file is not in items', ['hi_IN/core'], $items);
$mk()->forget();
pin('forget', null, $read());

exit(harness_result());
