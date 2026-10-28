<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator\admin;

/**
 * The rows of the Translator list: every source string joined with the owner's text, then
 * searched, filtered and cut into pages.
 */
final class Listing
{
    public const PER_PAGE  = 50;
    public const FILTERS   = ['all', 'changed', 'look'];
    public const MAX_QUERY = 200;

    /**
     * Rows keep the source order. Entries the source no longer has come first, so they show up
     * under "All" too.
     *
     * @param array<string, array> $strings SourceStrings::read()
     * @param array<string, array> $custom  CustomStore::read()
     * @param array<string, array> $flagged SyncCheck::compare()
     * @return array<string, array>
     */
    public static function rows(array $strings, array $custom, array $flagged = []): array
    {
        $rows = [];
        foreach ($flagged as $key => $f) {
            if (isset($strings[$key]) || !isset($custom[$key]) || !in_array('removed', $f['flags'], true)) {
                continue;
            }
            $c          = $custom[$key];
            $rows[$key] = [
                'key'        => $key,
                'context'    => $c['context'],
                'original'   => $c['original'],
                'plural'     => $c['plural'],
                'shipped'    => $c['replaced'] !== [] ? array_values($c['replaced']) : [$c['original']],
                'translated' => false,
                'text'       => array_values((array) $c['text']),
                'status'     => 'changed',
                'flags'      => $f['flags'],
                'now'        => $f['now'] ?? [],
                'problems'   => $f['problems'] ?? [],
            ];
        }
        foreach ($strings as $key => $s) {
            $text = null;
            if (isset($custom[$key])) {
                foreach ((array) $custom[$key]['text'] as $form) {
                    if ($form !== '') {
                        $text = array_values((array) $custom[$key]['text']);
                        break;
                    }
                }
            }
            $rows[$key] = [
                'key'        => $key,
                'context'    => $s['context'],
                'original'   => $s['original'],
                'plural'     => $s['plural'],
                'shipped'    => $s['shipped'],
                'translated' => $s['translated'],
                'text'       => $text,
                'status'     => $text === null ? '' : 'changed',
                'flags'      => $flagged[$key]['flags'] ?? [],
                'now'        => $flagged[$key]['now'] ?? [],
                'problems'   => $flagged[$key]['problems'] ?? [],
            ];
        }

        return $rows;
    }

    public static function matches(array $row, string $q): bool
    {
        if ($q === '') {
            return true;
        }
        if (!preg_match('//u', $q)) {
            return false;
        }
        $hay = array_merge([(string) $row['original'], (string) ($row['plural'] ?? '')], (array) ($row['text'] ?? []));
        foreach ($hay as $h) {
            if ($h !== '' && preg_match('//u', $h) && mb_stripos($h, $q) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{rows: array, total: int, page: int, pages: int, from: int, to: int}
     */
    public static function page(array $rows, string $q, string $filter, int $page): array
    {
        $hits = [];
        foreach ($rows as $row) {
            if ($filter === 'changed' && $row['status'] !== 'changed') {
                continue;
            }
            if ($filter === 'look' && $row['flags'] === []) {
                continue;
            }
            if (self::matches($row, $q)) {
                $hits[] = $row;
            }
        }
        $total = count($hits);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, $page), $pages);
        $slice = array_slice($hits, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $from  = $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1;

        return [
            'rows'  => $slice,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'from'  => $from,
            'to'    => $total === 0 ? 0 : $from + count($slice) - 1,
        ];
    }

    /** @return array{all: int, changed: int, look: int} */
    public static function counts(array $rows): array
    {
        $c = ['all' => count($rows), 'changed' => 0, 'look' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === 'changed') {
                $c['changed']++;
            }
            if ($row['flags'] !== []) {
                $c['look']++;
            }
        }

        return $c;
    }
}
