<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

final class TextCheck
{
    private const PLACEHOLDER = '/%(?:\d+\$)?[bcdeEfFgGosuxX]|\{[A-Za-z_][A-Za-z0-9_]*\}/';
    private const TAG         = '/<\/?[a-zA-Z][^>]*>/';
    private const UNSAFE      = '/<\s*script|javascript\s*:|\bon[a-z]+\s*=/i';

    /** @return string[] */
    public static function problems(string $original, string $text): array
    {
        if (trim($text) === '') {
            return [__('Enter a text', 'translator')];
        }

        $problems = [];
        $want     = self::placeholders($original);
        $have     = self::placeholders($text);

        foreach (self::subtract($want, $have) as $placeholder) {
            $problems[] = sprintf(__('Your text must keep %s', 'translator'), $placeholder);
        }
        foreach (self::subtract($have, $want) as $placeholder) {
            $problems[] = sprintf(
                __('Your text has a placeholder the original does not have: %s', 'translator'),
                $placeholder
            );
        }

        if (self::tags($original) !== self::tags($text)) {
            $problems[] = __('Your text must keep the same HTML tags as the original', 'translator');
        }
        if (preg_match(self::UNSAFE, $text) === 1) {
            $problems[] = __('Your text cannot contain scripts or event handlers', 'translator');
        }

        return $problems;
    }

    /** @return string[] */
    private static function placeholders(string $s): array
    {
        preg_match_all(self::PLACEHOLDER, str_replace('%%', '', $s), $m);
        sort($m[0]);

        return $m[0];
    }

    /**
     * Items of $a that are not matched by an item of $b, counting repeats.
     *
     * @param string[] $a
     * @param string[] $b
     * @return string[]
     */
    private static function subtract(array $a, array $b): array
    {
        foreach ($b as $item) {
            $i = array_search($item, $a, true);
            if ($i !== false) {
                unset($a[$i]);
            }
        }

        return array_values($a);
    }

    /** @return string[] */
    private static function tags(string $s): array
    {
        preg_match_all(self::TAG, $s, $m);
        $tags = array_map([self::class, 'normaliseTag'], $m[0]);
        sort($tags);

        return $tags;
    }

    /** Lowercases the tag name and attribute names; attribute values stay as written. */
    private static function normaliseTag(string $tag): string
    {
        $tag = trim((string) preg_replace('/\s+/', ' ', $tag));
        $tag = (string) preg_replace_callback(
            '/^<(\/?)([a-zA-Z][a-zA-Z0-9]*)/',
            static fn (array $m): string => '<' . $m[1] . strtolower($m[2]),
            $tag
        );

        return (string) preg_replace_callback(
            '/([^\s=\/<>"\']+)(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?/',
            static fn (array $m): string => ($m[0][0] === '<') ? $m[0] : strtolower($m[1]) . ($m[2] ?? ''),
            $tag
        );
    }
}
