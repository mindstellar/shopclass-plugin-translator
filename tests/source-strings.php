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

// Source is built in its own task; fall back to a same-shaped stand-in until it lands.
if (!class_exists(Source::class)) {
    $stub = tmpdir() . '/Source.php';
    file_put_contents($stub, <<<'PHP'
<?php
namespace mindstellar\translator;
final class Source
{
    public function __construct(private string $name, private string $kind, private string $domain, private string $label, private ?string $pot, private string $poDir, private string $poFile)
    {
    }
    public function potPath(): ?string
    {
        return $this->pot;
    }
    public function shippedPoPath(string $locale): ?string
    {
        $p = $this->poDir . $locale . '/' . $this->poFile;
        return is_file($p) ? $p : null;
    }
    public function originalsPath(string $locale): ?string
    {
        return $this->potPath() ?? $this->shippedPoPath($locale) ?? $this->shippedPoPath('en_US');
    }
}
PHP);
    require $stub;
}

$site  = __DIR__ . '/fixtures/site/oc-content/';
$core  = new Source('core', 'core', 'core', 'Core', $site . 'languages/core.pot', $site . 'languages/', 'core.po');
$gamma = new Source('plugin-gamma', 'plugin', 'gamma', 'Plugin: Gamma', null, $site . 'plugins/gamma/languages/', 'messages.po');
$none  = new Source('plugin-beta', 'plugin', 'beta', 'Plugin: Beta', null, $site . 'plugins/beta/languages/', 'messages.po');

harness_section('key');
pin('key joins context and original with \x04', "menu\x04Edit", SourceStrings::key('menu', 'Edit'));
check('context keeps same original apart', SourceStrings::key('', 'Edit') !== SourceStrings::key('menu', 'Edit'));

harness_section('core / hi_IN');
$hi = SourceStrings::read($core, 'hi_IN');
pin('seven entries', 7, count($hi));
pin('file order', [
    "\x04Seller's information",
    "\x04Click, or drop images here, to upload",
    "\x04%1\$s listings found in %2\$s",
    "\x04Read the <a href=\"%s\">rules</a> first",
    "menu\x04Edit",
    "\x04%d listing",
    "\x04Hello {name}",
], array_keys($hi));
$e1 = $hi[SourceStrings::key('', "Seller's information")] ?? [];
pin('entry 1 shipped', ['विक्रेता की जानकारी'], $e1['shipped'] ?? null);
pin('entry 1 translated', true, $e1['translated'] ?? null);
pin('entry 1 references', ['oc-includes/a.php'], $e1['references'] ?? null);
pin('entry 1 no plural', null, array_key_exists('plural', $e1) ? $e1['plural'] : 'x');
$e2 = $hi[SourceStrings::key('', 'Click, or drop images here, to upload')] ?? [];
pin('entry 2 shipped is the original', ['Click, or drop images here, to upload'], $e2['shipped'] ?? null);
pin('entry 2 not translated', false, $e2['translated'] ?? null);
$e3 = $hi[SourceStrings::key('', '%1$s listings found in %2$s')] ?? [];
pin('entry 3 keeps placeholders', ['%2$s में %1$s विज्ञापन मिले'], $e3['shipped'] ?? null);
$e5 = $hi[SourceStrings::key('menu', 'Edit')] ?? [];
pin('context entry found', 'menu', $e5['context'] ?? null);
pin('context entry untranslated', ['Edit'], $e5['shipped'] ?? null);
$e6 = $hi[SourceStrings::key('', '%d listing')] ?? [];
pin('entry 6 plural', '%d listings', $e6['plural'] ?? null);
pin('entry 6 two shipped forms', ['%d विज्ञापन', '%d विज्ञापन'], $e6['shipped'] ?? null);
pin('entry 6 translated', true, $e6['translated'] ?? null);
pin('entry 7 braces kept', ['Hello {name}'], ($hi[SourceStrings::key('', 'Hello {name}')] ?? [])['shipped'] ?? null);

harness_section('core / en_US');
$en = SourceStrings::read($core, 'en_US');
pin('seven entries', 7, count($en));
$allUntranslated = true;
$allIds          = true;
foreach ($en as $e) {
    $allUntranslated = $allUntranslated && $e['translated'] === false;
    $ids             = $e['plural'] === null ? [$e['original']] : [$e['original'], $e['plural']];
    $allIds          = $allIds && $e['shipped'] === $ids;
}
check('none translated', $allUntranslated);
check('shipped equals the ids', $allIds);

harness_section('originals from another locale');
$g = SourceStrings::read($gamma, 'hi_IN');
pin('gamma ids come from en_US po', [SourceStrings::key('', 'Gamma says hi')], array_keys($g));
pin('gamma not translated in hi_IN', false, $g[SourceStrings::key('', 'Gamma says hi')]['translated'] ?? null);
pin('gamma shipped is the original', ['Gamma says hi'], $g[SourceStrings::key('', 'Gamma says hi')]['shipped'] ?? null);
pin('source without originals', [], SourceStrings::read($none, 'hi_IN'));

harness_section('disabled entries');
$dir = tmpdir();
file_put_contents($dir . '/t.pot', "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\nmsgid \"Live\"\nmsgstr \"\"\n\n#~ msgid \"Gone\"\n#~ msgstr \"\"\n");
$tmp = new Source('plugin-t', 'plugin', 't', 'T', $dir . '/t.pot', $dir . '/', 'messages.po');
pin('disabled skipped', [SourceStrings::key('', 'Live')], array_keys(SourceStrings::read($tmp, 'hi_IN')));

harness_section('shipped po missing an id');
mkdir($dir . '/m/hi_IN', 0755, true);
file_put_contents($dir . '/m/m.pot', "msgid \"\"\nmsgstr \"\"\n\nmsgid \"One\"\nmsgstr \"\"\n\nmsgid \"Two\"\nmsgstr \"\"\n");
file_put_contents($dir . '/m/hi_IN/messages.po', "msgid \"\"\nmsgstr \"\"\n\nmsgid \"One\"\nmsgstr \"Ek\"\n");
$mm = new Source('plugin-m', 'plugin', 'm', 'M', $dir . '/m/m.pot', $dir . '/m/', 'messages.po');
$m  = SourceStrings::read($mm, 'hi_IN');
pin('ids come from the pot', 2, count($m));
pin('translated one', ['Ek'], $m[SourceStrings::key('', 'One')]['shipped'] ?? null);
pin('missing one falls back', ['Two'], $m[SourceStrings::key('', 'Two')]['shipped'] ?? null);

harness_section('pluralForms');
pin('shipped header', 'nplurals=2; plural=(n != 1);', SourceStrings::pluralForms($core, 'hi_IN'));
pin('default when none', 'nplurals=2; plural=(n != 1);', SourceStrings::pluralForms($gamma, 'hi_IN'));
file_put_contents($dir . '/p.pot', "msgid \"\"\nmsgstr \"\"\n\"Plural-Forms: nplurals=1; plural=0;\\n\"\n");
$pp = new Source('plugin-p', 'plugin', 'p', 'P', $dir . '/p.pot', $dir . '/', 'messages.po');
pin('falls back to the pot header', 'nplurals=1; plural=0;', SourceStrings::pluralForms($pp, 'hi_IN'));

harness_section('nplurals is capped');
mkdir($dir . '/big/hi_IN', 0755, true);
file_put_contents($dir . '/big/big.pot', "msgid \"\"\nmsgstr \"\"\n\"Plural-Forms: nplurals=3; plural=n%3;\\n\"\n\nmsgid \"%d ad\"\nmsgid_plural \"%d ads\"\nmsgstr[0] \"\"\n");
file_put_contents($dir . '/big/hi_IN/messages.po', "msgid \"\"\nmsgstr \"\"\n\"Plural-Forms: nplurals=99999999; plural=n;\\n\"\n\nmsgid \"%d ad\"\nmsgid_plural \"%d ads\"\nmsgstr[0] \"x\"\nmsgstr[1] \"y\"\n");
$big = new Source('plugin-big', 'plugin', 'big', 'Big', $dir . '/big/big.pot', $dir . '/big/', 'messages.po');
pin('huge nplurals reads as 2 forms', ['x', 'y'], SourceStrings::read($big, 'hi_IN')[SourceStrings::key('', '%d ad')]['shipped'] ?? null);
pin('huge nplurals header falls back to the pot', 'nplurals=3; plural=n%3;', SourceStrings::pluralForms($big, 'hi_IN'));
pin('pluralCount 6', 6, SourceStrings::pluralCount('nplurals=6; plural=n;'));
pin('pluralCount 7', null, SourceStrings::pluralCount('nplurals=7; plural=n;'));
pin('pluralCount 0', null, SourceStrings::pluralCount('nplurals=0; plural=0;'));

exit(harness_result());
