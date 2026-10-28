<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\TextCheck;

$keep  = static fn (string $p): string => 'Your text must keep ' . $p;
$extra = static fn (string $p): string => 'Your text has a placeholder the original does not have: ' . $p;
$html  = 'Your text must keep the same HTML tags as the original';
$bad   = 'Your text cannot contain scripts or event handlers';

harness_section('placeholders');
pin('renamed words ok', [], TextCheck::problems('%s listings found', '%s ads found'));
pin('dropped %s', [$keep('%s')], TextCheck::problems('%s listings found', 'listings found'));
pin('%s to %d', [$keep('%s'), $extra('%d')], TextCheck::problems('%s listings found', '%d listings found'));
pin('swapped positional ok', [], TextCheck::problems('%1$s in %2$s', '%2$s mein %1$s'));
pin('%% ignored', [], TextCheck::problems('100%% sure', '100 sure'));
pin('%%d is not a placeholder', [], TextCheck::problems('100%%d done', '100 done'));
pin('%%s in the text is not extra', [], TextCheck::problems('done', 'done 100%%s'));
pin('lone % ignored', [], TextCheck::problems('50% off', '50% chhoot'));
pin('{name} missing', [$keep('{name}')], TextCheck::problems('Hello {name}', 'Hello'));
pin('repeated placeholder needs both', [$keep('%s')], TextCheck::problems('%s and %s', '%s and x'));

harness_section('html');
pin('href changed', [$html], TextCheck::problems('Read <a href="%s">rules</a>', 'Read <a href="/x/%s">rules</a>'));
pin('attribute value case matters', [$html], TextCheck::problems('<a href="/Rules">r</a>', '<a href="/rules">r</a>'));
pin('tags reordered ok', [], TextCheck::problems('<b>x</b> <i>y</i>', '<i>y</i> <b>x</b>'));
pin('tag name case ok', [], TextCheck::problems('<a href="%s">r</a>', '<A HREF="%s">r</A>'));
pin('extra br', [$html], TextCheck::problems('a b', 'a<br>b'));
pin('tags on plain original', [$html], TextCheck::problems('plain', '<b>plain</b>'));
pin('script refused', [$bad], TextCheck::problems('<script>x</script>', '<script>y</script>'));
pin('onclick refused', [$bad], TextCheck::problems('<b onclick="a">x</b>', '<b onclick="a">y</b>'));
pin('javascript: refused', [$bad], TextCheck::problems('<a href="javascript:a">x</a>', '<a href="javascript:a">y</a>'));
pin('upper-case SCRIPT refused', [$bad], TextCheck::problems('<SCRIPT>x</SCRIPT>', '<SCRIPT>y</SCRIPT>'));
pin('upper-case ONCLICK refused', [$bad], TextCheck::problems('<b ONCLICK="a">x</b>', '<b ONCLICK="a">y</b>'));
pin('upper-case JAVASCRIPT: refused', [$bad], TextCheck::problems('<a href="JAVASCRIPT:a">x</a>', '<a href="JAVASCRIPT:a">y</a>'));
pin('extra spaces inside a tag ok', [], TextCheck::problems('<a href="%s">r</a>', '<a   href="%s">r</a>'));
pin('newline inside a tag ok', [], TextCheck::problems('<a href="%s">r</a>', "<a\nhref=\"%s\">r</a>"));
pin('plain "reason=" is fine', [], TextCheck::problems('reason', 'reason=none'));

harness_section('empty');
pin('empty', ['Enter a text'], TextCheck::problems('Hello', ''));
pin('blank', ['Enter a text'], TextCheck::problems('Hello', "  \n "));

exit(harness_result());
