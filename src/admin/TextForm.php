<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator\admin;

use mindstellar\translator\SourceStrings;
use mindstellar\translator\TextCheck;

final class TextForm
{
    /** Line breaks become "\n"; trailing spaces on each line and at the end are dropped. */
    public static function normalise(string $text): string
    {
        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_map('rtrim', explode("\n", $text));

        return rtrim(implode("\n", $lines));
    }

    /**
     * @param array<string, array> $strings SourceStrings::read() result
     * @return array{entry: array|null, reset: bool, errors: string[]}
     */
    public static function validate(array $strings, string $context, string $original, array $text, int $nplurals): array
    {
        $row = $strings[SourceStrings::key($context, $original)] ?? null;
        if ($row === null) {
            return [
                'entry'  => null,
                'reset'  => false,
                'errors' => [__('That text is not in this source any more', 'translator')],
            ];
        }

        $plural = (string) ($row['plural'] ?? '');
        $count  = $plural === '' ? 1 : max(1, $nplurals);
        $forms  = [];
        for ($i = 0; $i < $count; $i++) {
            $forms[] = self::normalise(is_scalar($text[$i] ?? null) ? (string) $text[$i] : '');
        }

        if (trim(implode('', $forms)) === '') {
            return ['entry' => null, 'reset' => true, 'errors' => []];
        }

        $errors = [];
        foreach ($forms as $i => $form) {
            foreach (TextCheck::problems($i === 0 ? $original : $plural, $form) as $problem) {
                $errors[] = $count > 1 ? sprintf(__('Plural form %1$d: %2$s', 'translator'), $i + 1, $problem) : $problem;
            }
        }
        if ($errors !== []) {
            return ['entry' => null, 'reset' => false, 'errors' => $errors];
        }

        return [
            'entry' => [
                'context'    => $context,
                'original'   => $original,
                'plural'     => $row['plural'] ?? null,
                'text'       => $forms,
                'replaced'   => $row['shipped'],
                'references' => $row['references'],
            ],
            'reset'  => false,
            'errors' => [],
        ];
    }
}
