<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\admin\TextForm;
use mindstellar\translator\Source;
use mindstellar\translator\SourceStrings;

$site = __DIR__ . '/fixtures/site/oc-content/';
$core = new Source('core', 'core', 'core', 'Core', $site . 'languages/core.pot', $site . 'languages/', 'core.po');
$s    = SourceStrings::read($core, 'hi_IN');

$v = static fn (string $ctx, string $o, array $t, int $n = 2): array => TextForm::validate($s, $ctx, $o, $t, $n);

harness_section('normalise');
pin('CRLF to LF', "a\nb", TextForm::normalise("a\r\nb"));
pin('CR to LF', "a\nb", TextForm::normalise("a\rb"));
pin('trailing spaces per line and at end', "a\nb", TextForm::normalise("a  \nb \n\n"));
pin('leading space kept', ' a', TextForm::normalise(' a'));

harness_section('single text');
$r = $v('', "Seller's information", ["Seller's details"]);
pin('no errors', [], $r['errors']);
pin('not a reset', false, $r['reset']);
pin('replaced is shipped', ['विक्रेता की जानकारी'], $r['entry']['replaced'] ?? null);
pin('references', ['oc-includes/a.php'], $r['entry']['references'] ?? null);
pin('text', ["Seller's details"], $r['entry']['text'] ?? null);

$r = $v('', 'Nope', ['x']);
pin('unknown: one error', ['That text is not in this source any more'], $r['errors']);
pin('unknown: no entry', null, $r['entry']);

pin('context entry found with context', [], $v('menu', 'Edit', ['Badlo'])['errors']);
pin('context entry not found without it', 1, count($v('', 'Edit', ['Badlo'])['errors']));

$r = $v('', "Seller's information", ['']);
pin('blank is a reset', true, $r['reset']);
pin('blank has no entry', null, $r['entry']);
pin('blank has no errors', [], $r['errors']);
pin('whitespace only is a reset', true, $v('', "Seller's information", ["  \r\n"])['reset']);

pin('dropped placeholder', ['Your text must keep %2$s'], $v('', '%1$s listings found in %2$s', ['%1$s mile'])['errors']);
check('script refused', in_array('Your text cannot contain scripts or event handlers', $v('', "Seller's information", ['<script>x'])['errors'], true));
pin('CRLF normalised in entry', ["a\nb"], $v('', "Seller's information", ["a\r\nb"])['entry']['text'] ?? null);
pin('non-plural keeps one form', ['x'], $v('', "Seller's information", ['x', 'y', 'z'], 3)['entry']['text'] ?? null);

harness_section('plural');
$r = $v('', '%d listing', ['%d ad', '']);
pin('blank second form', ['Plural form 2: Enter a text'], $r['errors']);
pin('blank second form: no entry', null, $r['entry']);
$r = $v('', '%d listing', ['%d ad', '%d ads']);
pin('two forms ok', [], $r['errors']);
pin('two forms kept', ['%d ad', '%d ads'], $r['entry']['text'] ?? null);
pin('plural kept in entry', '%d listings', $r['entry']['plural'] ?? null);
pin('extra form cut', ['%d ad', '%d ads'], $v('', '%d listing', ['%d ad', '%d ads', 'extra'])['entry']['text'] ?? null);
pin('missing forms padded then refused', ['Plural form 2: Enter a text'], $v('', '%d listing', ['%d ad'])['errors']);
pin('plural form checked against plural', ['Plural form 2: Your text must keep %d'], $v('', '%d listing', ['%d ad', 'ads'])['errors']);

$other = [\mindstellar\translator\SourceStrings::key('', '%d item') => ['context' => '', 'original' => '%d item', 'plural' => '%d items, %s', 'shipped' => ['x'], 'references' => []]];
$r = TextForm::validate($other, '', '%d item', ['%d thing', '%d things'], 2);
pin('later forms are checked against the plural', ['Plural form 2: Your text must keep %s'], $r['errors']);

exit(harness_result());
