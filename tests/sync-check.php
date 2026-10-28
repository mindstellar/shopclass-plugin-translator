<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\Source;
use mindstellar\translator\SourceStrings;
use mindstellar\translator\SyncCheck;

$lang = realpath(__DIR__ . '/fixtures/site') . '/oc-content/languages/';
$core = new Source('core', 'core', 'core', 'Core', $lang . 'core.pot', $lang, 'core.po');
$strings = SourceStrings::read($core, 'hi_IN');

function entry(string $context, string $original, ?string $plural, array $text, array $replaced): array
{
    return [
        'context' => $context, 'original' => $original, 'plural' => $plural,
        'text' => $text, 'replaced' => $replaced, 'references' => [],
    ];
}

function compareOne(array $strings, array $entry): array
{
    return SyncCheck::compare($strings, [SourceStrings::key($entry['context'], $entry['original']) => $entry]);
}

$seller = "Seller's information";
$k1     = SourceStrings::key('', $seller);
$k3     = SourceStrings::key('', '%1$s listings found in %2$s');
$k6     = SourceStrings::key('', '%d listing');

harness_section('newer');
pin('same shipped text is clean', [], compareOne($strings, entry('', $seller, null, ['मेरा'], ['विक्रेता की जानकारी'])));
pin('older shipped text is newer', [$k1 => ['flags' => ['newer'], 'now' => ['विक्रेता की जानकारी'], 'problems' => []]], compareOne($strings, entry('', $seller, null, ['मेरा'], ['old'])));
pin('no stored original is not flagged', [], compareOne($strings, entry('', $seller, null, ['मेरा'], [])));

harness_section('removed');
$gone = compareOne($strings, entry('', 'Nope', null, ['Nein'], ['Nope']));
pin('unknown key', ['flags' => ['removed'], 'now' => [], 'problems' => []], $gone[SourceStrings::key('', 'Nope')]);
$edit = compareOne($strings, entry('', 'Edit', null, ['x'], []));
pin('wrong context is removed', ['removed'], $edit[SourceStrings::key('', 'Edit')]['flags']);

harness_section('placeholders');
$bad = compareOne($strings, entry('', '%1$s listings found in %2$s', null, ['%1$s listings'], ['%2$s में %1$s विज्ञापन मिले']));
pin('flag', ['placeholders'], $bad[$k3]['flags']);
check('problem text', in_array('Your text must keep %2$s', $bad[$k3]['problems'], true), json_encode($bad[$k3]['problems']));
$plural = compareOne($strings, entry('', '%d listing', '%d listings', ['%d ad', 'ads'], ['%d विज्ञापन', '%d विज्ञापन']));
pin('plural flag', ['placeholders'], $plural[$k6]['flags']);
check('plural problem prefix', strpos($plural[$k6]['problems'][0], 'Plural form 2: ') === 0, $plural[$k6]['problems'][0]);
$both = compareOne($strings, entry('', '%1$s listings found in %2$s', null, ['%1$s listings'], ['x']));
pin('newer comes before placeholders', ['newer', 'placeholders'], $both[$k3]['flags']);

harness_section('counts');
$mixed = SyncCheck::compare($strings, [
    $k1 => entry('', $seller, null, ['मेरा'], ['old']),
    SourceStrings::key('', 'Nope') => entry('', 'Nope', null, ['Nein'], []),
    SourceStrings::key('', 'Edit') => entry('', 'Edit', null, ['x'], []),
    $k3 => entry('', '%1$s listings found in %2$s', null, ['%1$s listings'], ['x']),
    $k6 => entry('', '%d listing', '%d listings', ['%d ad', 'ads'], []),
]);
pin('counts', ['removed' => 2, 'newer' => 2, 'placeholders' => 2, 'total' => 5], SyncCheck::counts($mixed));
pin('empty compare', [], SyncCheck::compare([], []));
pin('empty counts', ['removed' => 0, 'newer' => 0, 'placeholders' => 0, 'total' => 0], SyncCheck::counts([]));

exit(harness_result());
