<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

use Gettext\Generators\Mo;
use Gettext\Generators\Po;
use Gettext\Translation;
use Gettext\Translations;
use InvalidArgumentException;
use mindstellar\utility\FileSystem;
use RuntimeException;

/**
 * The owner's texts: one `.po` and its compiled `.mo` per locale and source, under the custom
 * languages folder.
 */
final class CustomStore
{
    public const COMMENT = 'replaced: ';

    /** Gettext turns this header into PHP code and evals it, so only plain plural arithmetic passes. */
    public const PLURAL_FORMS_PATTERN = '/^nplurals\s*=\s*[1-6]\s*;\s*plural\s*=\s*[n0-9\s<>=!&|?:%()+\-]+;?$/D';

    private string $dir;
    /** @var callable|null */
    private $afterWrite;

    public function __construct(string $dir, ?callable $afterWrite = null)
    {
        $this->dir        = rtrim($dir, '/') . '/';
        $this->afterWrite = $afterWrite;
    }

    public function poPath(string $locale, Source $s): string
    {
        return $this->stem($locale, $s) . '.po';
    }

    public function moPath(string $locale, Source $s): string
    {
        return $this->stem($locale, $s) . '.mo';
    }

    /** The copy of the .po taken before the last upload or remove all. */
    public function backupPath(string $locale, Source $s): string
    {
        return $this->poPath($locale, $s) . '.bak';
    }

    public function hasBackup(string $locale, Source $s): bool
    {
        return is_file($this->backupPath($locale, $s));
    }

    /** Copies the current .po over the backup; with no .po the backup is empty, and undo then removes the file. */
    public function backup(string $locale, Source $s): void
    {
        $po      = $this->poPath($locale, $s);
        $bak     = $this->backupPath($locale, $s);
        $content = is_file($po) ? file_get_contents($po) : '';
        if (!is_string($content)) {
            throw new RuntimeException('Could not read ' . $po);
        }
        $this->ensureDir(dirname($bak));
        if (!FileSystem::writeAtomic($bak, $content, 0644)) {
            throw new RuntimeException('Could not write ' . $bak);
        }
    }

    /** Puts the backup back as the .po, builds the .mo from it and deletes the backup. An empty backup removes the .po and .mo. */
    public function restore(string $locale, Source $s): void
    {
        $bak     = $this->backupPath($locale, $s);
        $content = is_file($bak) ? file_get_contents($bak) : false;
        if (!is_string($content)) {
            throw new RuntimeException('No backup at ' . $bak);
        }
        if ($content === '') {
            self::delete($this->moPath($locale, $s), $this->poPath($locale, $s), $bak);
            $this->notify($locale, $s);

            return;
        }
        $t      = Translations::fromPoString($content);
        $header = $t->getHeader('Plural-Forms');
        $t->setHeader('Plural-Forms', self::pluralFormsFor(is_string($header) ? $header : '', $s, $locale));
        $po = $this->poPath($locale, $s);
        $this->ensureDir(dirname($po));
        self::save($po, $this->moPath($locale, $s), $content, $t);
        self::delete($bak);
        $this->notify($locale, $s);
    }

    /** Removes every entry for the locale and source; returns how many there were. */
    public function removeAll(string $locale, Source $s): int
    {
        $count = count($this->read($locale, $s));
        $this->write($locale, $s, [], '');

        return $count;
    }

    /**
     * Adds the entries over the current ones: a matching key is overwritten, the others stay.
     *
     * @param array<array-key, array<string, mixed>> $entries as read() returns them
     */
    public function merge(string $locale, Source $s, array $entries, string $pluralForms): void
    {
        $this->replaceAll($locale, $s, array_merge(array_values($this->read($locale, $s)), array_values($entries)), $pluralForms);
    }

    /**
     * @return array<string, array{context:string, original:string, plural:?string, text:string[], replaced:string[], references:string[]}>
     */
    public function read(string $locale, Source $s): array
    {
        $po = $this->poPath($locale, $s);
        if (!is_file($po)) {
            return [];
        }
        $t      = Translations::fromPoFile($po);
        $header = $t->getHeader('Plural-Forms');
        $count  = (is_string($header) ? SourceStrings::pluralCount($header) : null)
            ?? SourceStrings::pluralCount(SourceStrings::pluralForms($s, $locale))
            ?? 2;
        $out    = [];
        foreach ($t as $tr) {
            if ($tr->isDisabled() || $tr->getOriginal() === '') {
                continue;
            }
            $plural = $tr->hasPlural() ? (string) $tr->getPlural() : null;
            $text   = self::formsOf($tr, $count);
            if (self::blank($text)) {
                continue;
            }
            $replaced = self::replacedFrom($tr->getExtractedComments());
            $refs = [];
            foreach ($tr->getReferences() as $ref) {
                $refs[] = (string) $ref[0];
            }
            $context       = (string) $tr->getContext();
            $original      = (string) $tr->getOriginal();
            $out[SourceStrings::key($context, $original)] = [
                'context'    => $context,
                'original'   => $original,
                'plural'     => $plural,
                'text'       => $text,
                'replaced'   => $replaced,
                'references' => array_values(array_unique($refs)),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $entry one entry as read() returns it; an all-empty `text` removes it */
    public function set(string $locale, Source $s, array $entry, string $pluralForms): void
    {
        $entry = self::entry($entry);
        if ($entry === null) {
            throw new InvalidArgumentException('An entry needs an original text');
        }
        if (self::blank($entry['text'])) {
            $this->remove($locale, $s, $entry['context'], $entry['original']);

            return;
        }
        $entries = $this->read($locale, $s);
        $entries[SourceStrings::key($entry['context'], $entry['original'])] = $entry;
        $this->write($locale, $s, $entries, $pluralForms);
    }

    public function remove(string $locale, Source $s, string $context, string $original): void
    {
        $entries = $this->read($locale, $s);
        $key     = SourceStrings::key($context, $original);
        if (!isset($entries[$key])) {
            return;
        }
        unset($entries[$key]);
        $forms = $this->pluralFormsOf($this->poPath($locale, $s));
        $this->write($locale, $s, $entries, $forms);
    }

    /** @param array<array-key, array<string, mixed>> $entries as read() returns them */
    public function replaceAll(string $locale, Source $s, array $entries, string $pluralForms): void
    {
        $clean = [];
        foreach ($entries as $e) {
            $entry = is_array($e) ? self::entry($e) : null;
            if ($entry !== null && !self::blank($entry['text'])) {
                $clean[SourceStrings::key($entry['context'], $entry['original'])] = $entry;
            }
        }
        $this->write($locale, $s, $clean, $pluralForms);
    }

    private function stem(string $locale, Source $s): string
    {
        if (!Locales::isCode($locale)) {
            throw new InvalidArgumentException('Not a locale code: ' . $locale);
        }
        if (!Sources::isName($s->name())) {
            throw new InvalidArgumentException('Not a source name: ' . $s->name());
        }

        return $this->dir . $locale . '/' . $s->name();
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function write(string $locale, Source $s, array $entries, string $pluralForms): void
    {
        $po = $this->poPath($locale, $s);
        $mo = $this->moPath($locale, $s);
        if ($entries === []) {
            self::delete($mo, $po);
        } else {
            $this->ensureDir(dirname($po));
            $t = $this->build($locale, $s, $entries, $pluralForms);
            self::save($po, $mo, Po::toString($t), $t);
        }
        $this->notify($locale, $s);
    }

    /** Writes the .po, then the .mo; when the .mo fails the old .po is put back. */
    private static function save(string $po, string $mo, string $poText, Translations $t): void
    {
        clearstatcache(true, $mo);
        $prevMtime = is_file($mo) ? (int) filemtime($mo) : 0;
        $prevPo    = is_file($po) ? file_get_contents($po) : false;

        if (!FileSystem::writeAtomic($po, $poText, 0644)) {
            throw new RuntimeException('Could not write ' . $po);
        }
        if (!FileSystem::writeAtomic($mo, Mo::toString($t), 0644)) {
            if (is_string($prevPo)) {
                FileSystem::writeAtomic($po, $prevPo, 0644);
            } else {
                @unlink($po);
            }
            throw new RuntimeException('Could not write ' . $mo);
        }
        self::touchNewer($mo, $prevMtime);
    }

    /** Core caches a compiled catalogue by size and mtime, so a rewrite must look newer than before. */
    private static function touchNewer(string $file, int $prevMtime): void
    {
        clearstatcache(true, $file);
        if ((int) filemtime($file) <= $prevMtime) {
            @touch($file, $prevMtime + 1);
            clearstatcache(true, $file);
        }
    }

    private static function delete(string ...$files): void
    {
        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file)) {
                throw new RuntimeException('Could not remove ' . $file);
            }
        }
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function build(string $locale, Source $s, array $entries, string $pluralForms): Translations
    {
        $t = self::catalogue($locale, self::pluralFormsFor($pluralForms, $s, $locale), $s->domain());
        $t->setHeader('X-Generator', 'translator');
        $t->setHeader('X-Translator-Source', $s->name());

        uksort($entries, 'strcmp');
        foreach ($entries as $e) {
            self::insert($t, $e)->addExtractedComment(
                self::COMMENT . json_encode($e['replaced'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $t;
    }

    /** An empty catalogue with the Language, Content-Type and Plural-Forms headers and the domain set. */
    public static function catalogue(string $locale, string $pluralForms, string $domain): Translations
    {
        $t = new Translations();
        $t->deleteHeaders();
        $t->setHeader('Language', $locale);
        $t->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        $t->setHeader('Plural-Forms', $pluralForms);
        $t->setDomain($domain);

        return $t;
    }

    /**
     * Adds one entry with its text forms and references.
     *
     * @param array{context:string, original:string, plural:?string, text:string[], references:string[]} $e
     */
    public static function insert(Translations $t, array $e): Translation
    {
        $tr   = $t->insert($e['context'], $e['original'], (string) ($e['plural'] ?? ''));
        $text = $e['text'];
        $tr->setTranslation((string) array_shift($text));
        if ($e['plural'] !== null && $e['plural'] !== '') {
            $tr->setPluralTranslations($text);
        }
        foreach ($e['references'] as $ref) {
            $ref = preg_replace('/\s+/', '', $ref);
            if ($ref !== '' && $ref !== null) {
                $tr->addReference($ref);
            }
        }

        return $tr;
    }

    /** The given header when it is safe, else the source's own, else the default. */
    private static function pluralFormsFor(string $given, Source $s, string $locale): string
    {
        foreach ([$given, SourceStrings::pluralForms($s, $locale)] as $forms) {
            $forms = self::line($forms);
            if (preg_match(self::PLURAL_FORMS_PATTERN, $forms) === 1) {
                return $forms;
            }
        }

        return SourceStrings::DEFAULT_PLURAL_FORMS;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not write ' . $dir);
        }
        FileSystem::protectFolder($dir);
    }

    private function pluralFormsOf(string $po): string
    {
        $header = is_file($po) ? Translations::fromPoFile($po)->getHeader('Plural-Forms') : null;

        return is_string($header) ? $header : '';
    }

    private function notify(string $locale, Source $s): void
    {
        if ($this->afterWrite !== null) {
            ($this->afterWrite)($locale, $s);
        }
    }

    private static function line(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    /**
     * One entry in the shape read() returns, from loosely typed input.
     *
     * @return array{context:string, original:string, plural:?string, text:string[], replaced:string[], references:string[]}|null
     */
    private static function entry(array $e): ?array
    {
        $original = $e['original'] ?? null;
        if (!is_string($original) || $original === '') {
            return null;
        }
        $plural = $e['plural'] ?? null;

        return [
            'context'    => is_string($e['context'] ?? null) ? $e['context'] : '',
            'original'   => $original,
            'plural'     => is_string($plural) && $plural !== '' ? $plural : null,
            'text'       => self::strings((array) ($e['text'] ?? [])),
            'replaced'   => self::strings((array) ($e['replaced'] ?? [])),
            'references' => self::strings((array) ($e['references'] ?? [])),
        ];
    }

    /** @return string[] */
    /**
     * An entry's text forms: the translation, then its plural forms sized to $nplurals - 1.
     *
     * @return string[]
     */
    public static function formsOf(Translation $tr, int $nplurals): array
    {
        $text = [(string) $tr->getTranslation()];
        if ($tr->hasPlural()) {
            foreach ($tr->getPluralTranslations(max(0, $nplurals - 1)) as $form) {
                $text[] = (string) $form;
            }
        }

        return $text;
    }

    /**
     * The texts an entry's `replaced:` comment lists, strings only; [] when there is none.
     *
     * @return string[]
     */
    public static function replacedFrom(array $comments): array
    {
        $replaced = [];
        foreach ($comments as $comment) {
            if (strncmp((string) $comment, self::COMMENT, strlen(self::COMMENT)) === 0) {
                $decoded  = json_decode(substr((string) $comment, strlen(self::COMMENT)), true);
                $replaced = is_array($decoded) ? self::strings($decoded) : [];
            }
        }

        return $replaced;
    }

    private static function strings(array $list): array
    {
        $out = [];
        foreach ($list as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private static function blank(array $text): bool
    {
        foreach ($text as $v) {
            if ($v !== '') {
                return false;
            }
        }

        return true;
    }
}
