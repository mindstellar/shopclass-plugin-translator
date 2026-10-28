<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use Gettext\Translations;

$root = dirname(__DIR__);
$site = __DIR__ . '/fixtures/site/oc-content';

function header_field(string $file, string $name): string
{
    return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\s*$/mi', (string) file_get_contents($file), $m) ? $m[1] : '';
}

harness_section('plugin header');
pin('Requires Shopclass', '7.0.6', header_field($root . '/index.php', 'Requires Shopclass'));
pin('Requires PHP', '8.0', header_field($root . '/index.php', 'Requires PHP'));
pin('Version', '0.1.0', header_field($root . '/index.php', 'Version'));
$json = json_decode((string) file_get_contents($root . '/shopclass.json'), true);
pin('shopclass.json slug is Short Name', header_field($root . '/index.php', 'Short Name'), $json['slug'] ?? null);

harness_section('autoloader');
check('Plugin class resolves', class_exists('mindstellar\\translator\\Plugin'));
pin('Plugin slug', 'translator', mindstellar\translator\Plugin::SLUG);

harness_section('fixtures');
$files = array(
    'languages/core.pot', 'languages/messages.pot', 'languages/en_US/core.po', 'languages/en_US/messages.po',
    'languages/hi_IN/core.po', 'languages/hi_IN/messages.po', 'themes/demo/languages/demo.pot',
    'themes/demo/languages/hi_IN/theme.po', 'plugins/alpha/languages/alpha.pot',
    'plugins/alpha/languages/hi_IN/messages.po', 'plugins/gamma/languages/en_US/messages.po',
);
foreach ($files as $f) {
    $ok = false;
    try {
        Translations::fromPoFile($site . '/' . $f);
        $ok = true;
    } catch (Throwable $e) {
    }
    check("parses: $f", $ok);
}
$counts = array(
    'languages/core.pot' => 7, 'languages/messages.pot' => 2, 'themes/demo/languages/demo.pot' => 3,
    'plugins/alpha/languages/alpha.pot' => 2, 'plugins/gamma/languages/en_US/messages.po' => 1,
);
foreach ($counts as $f => $n) {
    pin("entries: $f", $n, count(Translations::fromPoFile($site . '/' . $f)));
}
$hi = Translations::fromPoFile($site . '/languages/hi_IN/core.po');
$done = 0;
foreach ($hi as $t) {
    if ($t->getTranslation() !== '') {
        $done++;
    }
}
pin('hi_IN core.po translated', 3, $done);
pin('hi_IN plural forms', 'nplurals=2; plural=(n != 1);', $hi->getHeader('Plural-Forms'));
check('context entry present', Translations::fromPoFile($site . '/languages/core.pot')->find('menu', 'Edit') !== null);
check('plugin beta has no languages dir', !is_dir($site . '/plugins/beta/languages'));

harness_section('compileMo');
$tmp = tmpdir();
compileMo($site . '/languages/hi_IN/core.po', $tmp . '/core.mo');
$mo = Translations::fromMoFile($tmp . '/core.mo');
$e  = $mo->find('', "Seller's information");
pin('mo round trip', 'विक्रेता की जानकारी', $e ? $e->getTranslation() : null);

exit(harness_result());
