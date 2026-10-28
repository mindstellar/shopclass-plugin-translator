<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\admin\PoUpload;
use mindstellar\translator\CustomStore;
use mindstellar\translator\Source;
use mindstellar\translator\SourceStrings;

$site = __DIR__ . '/fixtures/site/oc-content/';
$core = new Source('core', 'core', 'core', 'Core', $site . 'languages/core.pot', $site . 'languages/', 'core.po');
$s    = SourceStrings::read($core, 'hi_IN');
$k    = static fn (string $o, string $ctx = ''): string => SourceStrings::key($ctx, $o);

$head = "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n\n";
$po   = static fn (string $body): string => $head . $body;

$threw = static function (callable $f): ?string {
    try {
        $f();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
};

harness_section('parse');
$r = PoUpload::parse($po(
    "msgid \"Seller's information\"\nmsgstr \"Seller's details\"\n\n"
    . "#. replaced: [\"old one\"]\nmsgid \"%1\$s listings found in %2\$s\"\nmsgstr \"%1\$s ads in %2\$s\"\n\n"
    . "msgid \"Nope\"\nmsgstr \"Nah\"\n\n"
    . "msgid \"Hello {name}\"\nmsgstr \"\"\n\n"
    . "#~ msgid \"Read the <a href=\\\"%s\\\">rules</a> first\"\n#~ msgstr \"Old\"\n"
), $s, 2);
pin('saved 2', 2, $r['saved']);
pin('total counts the non-empty entries', 3, $r['total']);
pin('entry 1 text', ["Seller's details"], $r['entries'][$k("Seller's information")]['text'] ?? null);
pin('entry 1 replaced from the source', ['विक्रेता की जानकारी'], $r['entries'][$k("Seller's information")]['replaced'] ?? null);
pin('entry 1 references from the source', ['oc-includes/a.php'], $r['entries'][$k("Seller's information")]['references'] ?? null);
pin('entry 3 replaced from the file', ['old one'], $r['entries'][$k('%1$s listings found in %2$s')]['replaced'] ?? null);
pin('unknown', ['Nope'], $r['unknown']);
pin('nothing failed', [], $r['failed']);
check('empty msgstr skipped', !isset($r['entries'][$k('Hello {name}')]));
check('disabled entry skipped', !isset($r['entries'][$k('Read the <a href="%s">rules</a> first')]));
check('header never an entry', !isset($r['entries'][$k('')]));
pin('entries keyed as source keys', [$k("Seller's information"), $k('%1$s listings found in %2$s')], array_keys($r['entries']));

$r = PoUpload::parse($po("msgid \"%1\$s listings found in %2\$s\"\nmsgstr \"%1\$s ads\"\n"), $s, 2);
pin('dropped placeholder fails', ['%1$s listings found in %2$s' => ['Your text must keep %2$s']], $r['failed']);
pin('dropped placeholder: nothing saved', 0, $r['saved']);

$r = PoUpload::parse($po("msgid \"Edit\"\nmsgstr \"Badlo\"\n"), $s, 2);
pin('context entry without msgctxt is unknown', ['Edit'], $r['unknown']);
$r = PoUpload::parse($po("msgctxt \"menu\"\nmsgid \"Edit\"\nmsgstr \"Badlo\"\n"), $s, 2);
pin('context entry with msgctxt', ['Badlo'], $r['entries'][$k('Edit', 'menu')]['text'] ?? null);
pin('context kept', 'menu', $r['entries'][$k('Edit', 'menu')]['context'] ?? null);

$r = PoUpload::parse($po("msgid \"%d listing\"\nmsgid_plural \"%d listings\"\nmsgstr[0] \"%d ad\"\nmsgstr[1] \"%d ads\"\n"), $s, 2);
pin('plural with both forms', ['%d ad', '%d ads'], $r['entries'][$k('%d listing')]['text'] ?? null);
$r = PoUpload::parse($po("msgid \"%d listing\"\nmsgid_plural \"%d listings\"\nmsgstr[0] \"%d ad\"\nmsgstr[1] \"\"\n"), $s, 2);
pin('plural with one form blank fails', ['%d listing' => ['Plural form 2: Enter a text']], $r['failed']);
$r = PoUpload::parse($po("msgid \"%d listing\"\nmsgid_plural \"%d listings\"\nmsgstr[0] \"\"\nmsgstr[1] \"\"\nmsgstr[2] \"extra\"\n\nmsgid \"Hello {name}\"\nmsgstr \"Hi {name}\"\n"), $s, 2);
pin('forms past nplurals are dropped before counting, as a read does', 1, $r['total']);

$r = PoUpload::parse($po("msgid \"Hello {name}\"\nmsgstr \"\"\n\nmsgid \"Gone\"\nmsgstr \"\"\n"), $s, 2);
pin('only empty msgstr: nothing unknown', [], $r['unknown']);
pin('only empty msgstr: nothing saved', 0, $r['saved']);

$r = PoUpload::parse($po("#. replaced: {\"a\":1}\nmsgid \"Seller's information\"\nmsgstr \"x\"\n"), $s, 2);
pin('bad replaced comment falls back to the source', ['विक्रेता की जानकारी'], $r['entries'][$k("Seller's information")]['replaced'] ?? null);
$r = PoUpload::parse($po("#. replaced: [\"old\", 1]\nmsgid \"Seller's information\"\nmsgstr \"x\"\n"), $s, 2);
pin('replaced comment keeps its strings, as a read does', ['old'], $r['entries'][$k("Seller's information")]['replaced'] ?? null);

harness_section('refused content');
$notPo = 'The file is not a .po file we can read.';
pin('header only', $notPo, $threw(static fn () => PoUpload::parse($head, $s, 2)));
pin('junk text', $notPo, $threw(static fn () => PoUpload::parse("lorem ipsum ~~ 12 }{ \n\tqwerty", $s, 2)));
pin('empty string', $notPo, $threw(static fn () => PoUpload::parse('', $s, 2)));
pin('Latin-1 bytes', 'The file must be UTF-8.', $threw(static fn () => PoUpload::parse($po("msgid \"Seller's information\"\nmsgstr \"Vend\xE9\"\n"), $s, 2)));
pin('random bytes', 'The file must be UTF-8.', $threw(static fn () => PoUpload::parse("\xFF\xFE\x00\x81\xC3", $s, 2)));
pin('a BOM is fine', 1, PoUpload::parse("\xEF\xBB\xBF" . $po("msgid \"Seller's information\"\nmsgstr \"x\"\n"), $s, 2)['saved']);

harness_section('fileError');
$tmp = tmpdir() . '/up.po';
file_put_contents($tmp, 'x');
PoUpload::$isUploaded = static fn (string $f): bool => $f === $tmp;
$choose  = 'Choose a .po file.';
$large   = 'The file is larger than 2 MB.';
$arrive  = 'The file did not arrive. Try again.';
pin('no entry', $choose, PoUpload::fileError([]));
pin('array error', $choose, PoUpload::fileError(['error' => [4]]));
pin('no file', $choose, PoUpload::fileError(['error' => UPLOAD_ERR_NO_FILE]));
pin('ini size', $large, PoUpload::fileError(['error' => UPLOAD_ERR_INI_SIZE]));
pin('form size', $large, PoUpload::fileError(['error' => UPLOAD_ERR_FORM_SIZE]));
pin('over the cap', $large, PoUpload::fileError(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 2097153]));
pin('at the cap', null, PoUpload::fileError(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 2097152]));
pin('partial', $arrive, PoUpload::fileError(['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => $tmp, 'size' => 1]));
pin('not an uploaded file', $arrive, PoUpload::fileError(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/etc/passwd', 'size' => 1]));
pin('a real upload', null, PoUpload::fileError(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 1]));
pin('a smaller cap', $large, PoUpload::fileError(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 11], 10));
PoUpload::$isUploaded = 'is_uploaded_file';
pin('default check refuses a CLI file', $arrive, PoUpload::fileError(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 1]));

harness_section('round trip');
$store = new CustomStore(tmpdir());
$forms = SourceStrings::pluralForms($core, 'hi_IN');
$store->set('hi_IN', $core, ['original' => "Seller's information", 'text' => ["Seller's details"], 'replaced' => ['विक्रेता की जानकारी'], 'references' => ['oc-includes/a.php']], $forms);
$store->set('hi_IN', $core, ['original' => '%d listing', 'plural' => '%d listings', 'text' => ['%d ad', '%d ads'], 'replaced' => ['%d विज्ञापन', '%d विज्ञापन'], 'references' => []], $forms);
$r = PoUpload::parse((string) file_get_contents($store->poPath('hi_IN', $core)), $s, 2);
pin('round trip: saved 2', 2, $r['saved']);
$want = $store->read('hi_IN', $core);
ksort($want);
$got = $r['entries'];
ksort($got);
pin('round trip: same entries', $want, $got);
$store->replaceAll('hi_IN', $core, $r['entries'], $forms);
pin('round trip: replaceAll takes them', $want, $store->read('hi_IN', $core));

exit(harness_result());
