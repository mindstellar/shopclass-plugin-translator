<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\admin\Listing;
use mindstellar\translator\CustomStore;
use mindstellar\translator\Source;
use mindstellar\translator\SourceStrings;

$site  = realpath(__DIR__ . '/fixtures/site');
$lang  = $site . '/oc-content/languages/';
$core  = new Source('core', 'core', 'core', 'Core', $lang . 'core.pot', $lang, 'core.po');
$forms = 'nplurals=2; plural=(n != 1);';

$strings = SourceStrings::read($core, 'hi_IN');
$keys    = array_keys($strings);
$store   = new CustomStore(tmpdir() . '/plugin-data/translator/');
foreach ([0, 1] as $i) {
    $s = $strings[$keys[$i]];
    $store->set('hi_IN', $core, [
        'context' => $s['context'], 'original' => $s['original'], 'plural' => $s['plural'],
        'text' => ['Mine ' . $i . ' zebra'], 'replaced' => $s['shipped'], 'references' => $s['references'],
    ], $forms);
}
$custom = $store->read('hi_IN', $core);
$rows   = Listing::rows($strings, $custom);
$list   = array_values($rows);

harness_section('rows');
pin('7 rows', 7, count($rows));
pin('source order and keys', $keys, array_keys($rows));
pin('key matches SourceStrings::key', SourceStrings::key($list[4]['context'], $list[4]['original']), $list[4]['key']);
pin('row 1 changed', 'changed', $list[0]['status']);
pin('row 1 text is the custom list', ['Mine 0 zebra'], $list[0]['text']);
pin('row 3 text null', null, $list[2]['text']);
pin('row 3 status blank', '', $list[2]['status']);
pin('now and problems empty everywhere', [[]], array_values(array_unique(array_merge(array_column($list, 'now'), array_column($list, 'problems')), SORT_REGULAR)));
pin('flags empty everywhere', [], array_values(array_unique(array_merge(...array_column($list, 'flags')))));
pin('counts', ['all' => 7, 'changed' => 2, 'look' => 0], Listing::counts($rows));

harness_section('flagged');
$gone = SourceStrings::key('', 'Nope');
$gone2 = SourceStrings::key('', 'Nope two');
$entry = static fn (string $o, array $replaced): array => [
    'context' => '', 'original' => $o, 'plural' => null, 'text' => ['Nein'], 'replaced' => $replaced, 'references' => [],
];
$custom2 = $custom + [$gone => $entry('Nope', ['Nope!']), $gone2 => $entry('Nope two', [])];
$flag = [
    $gone => ['flags' => ['removed'], 'now' => [], 'problems' => []],
    $gone2 => ['flags' => ['removed'], 'now' => [], 'problems' => []],
    $keys[0] => ['flags' => ['newer'], 'now' => ['x'], 'problems' => []],
    $keys[2] => ['flags' => ['placeholders'], 'now' => [], 'problems' => ['Your text must keep %2$s']],
];
$frows = Listing::rows($strings, $custom2, $flag);
$fl    = array_values($frows);
pin('9 rows', 9, count($frows));
pin('removed rows come first, in order', [$gone, $gone2], array_slice(array_keys($frows), 0, 2));
pin('removed row key', $gone, $fl[0]['key']);
pin('removed row shipped is replaced', ['Nope!'], $fl[0]['shipped']);
pin('removed row text', ['Nein'], $fl[0]['text']);
pin('removed row not translated', false, $fl[0]['translated']);
pin('removed row status', 'changed', $fl[0]['status']);
pin('removed row flags', ['removed'], $fl[0]['flags']);
pin('removed without replaced uses original', ['Nope two'], $fl[1]['shipped']);
pin('newer row flags', ['newer'], $frows[$keys[0]]['flags']);
pin('newer row now', ['x'], $frows[$keys[0]]['now']);
pin('newer row status', 'changed', $frows[$keys[0]]['status']);
pin('placeholders row problems', ['Your text must keep %2$s'], $frows[$keys[2]]['problems']);
pin('flagged counts all', ['all' => 9, 'changed' => 4, 'look' => 4], Listing::counts($frows));
pin('look filter order', [$gone, $gone2, $keys[0], $keys[2]], array_column(Listing::page($frows, '', 'look', 1)['rows'], 'key'));
pin('query finds a removed row', [$gone, $gone2], array_column(Listing::page($frows, 'nein', 'all', 1)['rows'], 'key'));
pin('removed entry not in custom is skipped', 7, count(Listing::rows($strings, $custom, [$gone => ['flags' => ['removed'], 'now' => [], 'problems' => []]])));

harness_section('page');
pin('changed filter', 2, count(Listing::page($rows, '', 'changed', 1)['rows']));
$r = Listing::page($rows, 'LISTINGS', 'all', 1);
pin('LISTINGS matches two rows', [$list[2]['key'], $list[5]['key']], array_column($r['rows'], 'key'));
$r = Listing::page($rows, 'zebra', 'changed', 1);
pin('custom-text query finds the changed rows', 2, $r['total']);
pin('custom-text query, one row', [$list[1]['key']], array_column(Listing::page($rows, 'Mine 1', 'changed', 1)['rows'], 'key'));
$r = Listing::page($rows, '', 'look', 1);
pin('look: no rows', 0, count($r['rows']));
pin('look: pages', 1, $r['pages']);
pin('look: from', 0, $r['from']);
pin('look: to', 0, $r['to']);
$flagged           = $rows;
$flagged[$keys[3]]['flags'] = ['removed'];
pin('flagged row under look', [$keys[3]], array_column(Listing::page($flagged, '', 'look', 1)['rows'], 'key'));
pin('flagged counts', 1, Listing::counts($flagged)['look']);

$many = [];
for ($i = 1; $i <= 120; $i++) {
    $many['k' . $i] = ['key' => 'k' . $i, 'context' => '', 'original' => 'Text ' . $i, 'plural' => null,
        'shipped' => ['Text ' . $i], 'translated' => false, 'text' => null, 'status' => '', 'flags' => []];
}
$p = Listing::page($many, '', 'all', 3);
pin('page 3 rows', 20, count($p['rows']));
pin('page 3 from', 101, $p['from']);
pin('page 3 to', 120, $p['to']);
pin('pages', 3, $p['pages']);
pin('page 9 clamps', 3, Listing::page($many, '', 'all', 9)['page']);
pin('page 0 clamps', 1, Listing::page($many, '', 'all', 0)['page']);
pin('page 1 is full', 50, count(Listing::page($many, '', 'all', 1)['rows']));
pin('search then slice', 1, count(Listing::page($many, 'Text 120', 'all', 1)['rows']));

harness_section('bad query');
$threw = false;
try {
    $r = Listing::page($rows, "\xff\xfe", 'all', 1);
} catch (\Throwable $e) {
    $threw = true;
}
check('non-UTF-8 query does not throw', !$threw);
pin('non-UTF-8 query matches nothing', 0, $r['total'] ?? -1);

exit(harness_result());
