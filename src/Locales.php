<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

final class Locales
{
    /** Same grammar core accepts for a locale code. */
    public const PATTERN = '/^[A-Za-z]{2,3}_[A-Za-z]{2}$/D';

    public static function isCode(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }

    /** @return string[] */
    public static function installed(): array
    {
        $codes = \OSCLocale::getInstance()->listAllCodes();
        $out   = [];
        foreach (is_array($codes) ? $codes : [] as $code) {
            if (is_string($code) && self::isCode($code)) {
                $out[] = $code;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }
}
