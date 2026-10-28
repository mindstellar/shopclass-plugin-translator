<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator\admin;

use Gettext\Translations;
use InvalidArgumentException;
use mindstellar\translator\CustomStore;
use mindstellar\translator\SourceStrings;
use Throwable;

final class PoUpload
{
    public const MAX_BYTES = 2097152;

    /** @var callable(string): bool Tests replace it, since is_uploaded_file() is false on the command line. */
    public static $isUploaded = 'is_uploaded_file';

    /** The checks on one $_FILES entry; null when the file can be read. */
    public static function fileError(array $file, int $max = self::MAX_BYTES): ?string
    {
        $error = $file['error'] ?? null;
        if (!is_int($error) && !(is_string($error) && ctype_digit($error))) {
            return __('Choose a .po file.', 'translator');
        }
        $error = (int) $error;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return __('Choose a .po file.', 'translator');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return self::tooLarge();
        }
        $tmp = $file['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '') {
            return self::didNotArrive();
        }
        if ((int) ($file['size'] ?? 0) > $max) {
            return self::tooLarge();
        }
        if (!(self::$isUploaded)($tmp)) {
            return self::didNotArrive();
        }

        return null;
    }

    /**
     * @param array<string, array> $strings SourceStrings::read() result for the target locale and source
     * @return array{entries: array<string, array>, saved: int, total: int, unknown: string[], failed: array<string, string[]>}
     * @throws InvalidArgumentException when the content is not UTF-8 or holds no entries
     */
    public static function parse(string $content, array $strings, int $nplurals): array
    {
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }
        if (preg_match('//u', $content) !== 1) {
            throw new InvalidArgumentException(__('The file must be UTF-8.', 'translator'));
        }
        try {
            $translations = Translations::fromPoString($content);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(self::notPo());
        }

        $out  = ['entries' => [], 'saved' => 0, 'total' => 0, 'unknown' => [], 'failed' => []];
        $seen = 0;
        foreach ($translations as $tr) {
            $original = (string) $tr->getOriginal();
            if ($original === '' || $tr->isDisabled()) {
                continue;
            }
            $seen++;
            $text = CustomStore::formsOf($tr, $nplurals);
            if (implode('', $text) === '') {
                continue;
            }
            $out['total']++;

            $context = (string) $tr->getContext();
            $key     = SourceStrings::key($context, $original);
            if (!isset($strings[$key])) {
                $out['unknown'][] = $original;
                continue;
            }
            $result = TextForm::validate($strings, $context, $original, $text, $nplurals);
            if ($result['errors'] !== []) {
                $out['failed'][$original] = $result['errors'];
                continue;
            }
            if ($result['entry'] === null) {
                continue;
            }
            $entry = $result['entry'];
            $replaced = CustomStore::replacedFrom($tr->getExtractedComments());
            if ($replaced !== []) {
                $entry['replaced'] = $replaced;
            }
            $out['entries'][$key] = $entry;
        }
        if ($seen === 0) {
            throw new InvalidArgumentException(self::notPo());
        }
        $out['saved'] = count($out['entries']);

        return $out;
    }

    private static function notPo(): string
    {
        return __('The file is not a .po file we can read.', 'translator');
    }

    private static function tooLarge(): string
    {
        return __('The file is larger than 2 MB.', 'translator');
    }

    private static function didNotArrive(): string
    {
        return __('The file did not arrive. Try again.', 'translator');
    }
}
