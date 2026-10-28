<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

use mindstellar\translator\admin\TextForm;

/** Finds the owner's texts that no longer fit the current source. */
final class SyncCheck
{
    public const REMOVED      = 'removed';
    public const NEWER        = 'newer';
    public const PLACEHOLDERS = 'placeholders';
    public const FLAGS        = [self::REMOVED, self::NEWER, self::PLACEHOLDERS];

    /**
     * Every custom entry with a problem, keyed by SourceStrings::key(); clean entries are left out.
     *
     * @param array<string, array> $strings SourceStrings::read() result
     * @param array<string, array> $custom  CustomStore::read() result
     * @return array<string, array{flags: string[], now: string[], problems: string[]}>
     */
    public static function compare(array $strings, array $custom): array
    {
        $out = [];
        foreach ($custom as $key => $entry) {
            $row = $strings[$key] ?? null;
            if ($row === null) {
                $out[$key] = ['flags' => [self::REMOVED], 'now' => [], 'problems' => []];
                continue;
            }

            $flags    = [];
            $problems = [];
            $shipped  = array_values($row['shipped']);
            $replaced = array_values($entry['replaced'] ?? []);
            if ($replaced !== [] && $replaced !== $shipped) {
                $flags[] = self::NEWER;
            }
            $text   = array_values($entry['text']);
            $result = TextForm::validate($strings, (string) $entry['context'], (string) $entry['original'], $text, count($text));
            if ($result['errors'] !== []) {
                $flags[]  = self::PLACEHOLDERS;
                $problems = $result['errors'];
            }
            if ($flags !== []) {
                $out[$key] = ['flags' => $flags, 'now' => $shipped, 'problems' => $problems];
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{flags: string[]}> $flagged compare() result
     * @return array{removed:int, newer:int, placeholders:int, total:int} total counts entries, not flags
     */
    public static function counts(array $flagged): array
    {
        $counts = [self::REMOVED => 0, self::NEWER => 0, self::PLACEHOLDERS => 0, 'total' => count($flagged)];
        foreach ($flagged as $item) {
            foreach ($item['flags'] as $flag) {
                $counts[$flag]++;
            }
        }

        return $counts;
    }
}
