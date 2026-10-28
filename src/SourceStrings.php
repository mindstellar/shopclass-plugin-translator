<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

use Gettext\Translations;

final class SourceStrings
{
    public const DEFAULT_PLURAL_FORMS = 'nplurals=2; plural=(n != 1);';

    /** Gettext's largest real nplurals; a higher value in a header is treated as broken. */
    public const MAX_PLURALS = 6;

    public static function key(string $context, string $original): string
    {
        return $context . "\x04" . $original;
    }

    /**
     * @return array<string, array{context:string, original:string, plural:?string, shipped:string[], translated:bool, references:string[]}>
     */
    public static function read(Source $source, string $locale): array
    {
        $originals = $source->originalsPath($locale);
        if ($originals === null) {
            return [];
        }
        $shippedPath = $source->shippedPoPath($locale);
        $ids         = Translations::fromPoFile($originals);
        $shipped     = null;
        if ($shippedPath !== null) {
            $shipped = $shippedPath === $originals ? $ids : Translations::fromPoFile($shippedPath);
        }
        $nplurals = self::nplurals($shipped ?? $ids);

        $out = [];
        foreach ($ids as $t) {
            if ($t->isDisabled()) {
                continue;
            }
            $context  = (string) $t->getContext();
            $original = (string) $t->getOriginal();
            $plural   = $t->hasPlural() ? (string) $t->getPlural() : null;

            $match = $shipped === null ? null : $shipped->find($context, $original);
            if (!$match || $match->isDisabled()) {
                $match = null;
            }
            $forms = null;
            if ($match !== null && (string) $match->getTranslation() !== '') {
                $forms = [(string) $match->getTranslation()];
                if ($plural !== null) {
                    foreach ($match->getPluralTranslations() as $form) {
                        $forms[] = (string) $form;
                    }
                    $forms = array_slice($forms, 0, $nplurals);
                    while (count($forms) < $nplurals) {
                        $forms[] = $plural;
                    }
                }
            }
            $translated = $forms !== null;
            if ($forms === null) {
                $forms = $plural === null ? [$original] : [$original, $plural];
            }

            $refs = [];
            foreach ($t->getReferences() as $ref) {
                $refs[] = (string) $ref[0];
            }

            $out[self::key($context, $original)] = [
                'context'    => $context,
                'original'   => $original,
                'plural'     => $plural,
                'shipped'    => $forms,
                'translated' => $translated,
                'references' => $refs,
            ];
        }

        return $out;
    }

    public static function pluralForms(Source $source, string $locale): string
    {
        $paths = [$source->shippedPoPath($locale), $source->potPath()];
        foreach ($paths as $path) {
            if ($path === null || !is_file($path)) {
                continue;
            }
            $header = Translations::fromPoFile($path)->getHeader('Plural-Forms');
            if (is_string($header) && self::pluralCount($header) !== null) {
                return trim($header);
            }
        }

        return self::DEFAULT_PLURAL_FORMS;
    }

    /** The nplurals of a Plural-Forms header, or null when it is missing or outside 1..MAX_PLURALS. */
    public static function pluralCount(string $header): ?int
    {
        if (preg_match('/nplurals\s*=\s*(\d+)/', $header, $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];

        return $n >= 1 && $n <= self::MAX_PLURALS ? $n : null;
    }

    private static function nplurals(Translations $t): int
    {
        $header = $t->getHeader('Plural-Forms');

        return (is_string($header) ? self::pluralCount($header) : null) ?? 2;
    }
}
