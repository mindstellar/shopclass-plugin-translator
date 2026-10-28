<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

final class Source
{
    private string $name;
    private string $kind;
    private string $domain;
    private string $label;
    private ?string $pot;
    private string $poDir;
    private string $poFile;
    private string $folder;
    private string $templateFile;

    /** $folder is the theme or plugin folder; $templateFile is where a template made from it is kept. */
    public function __construct(
        string $name,
        string $kind,
        string $domain,
        string $label,
        ?string $pot,
        string $poDir,
        string $poFile,
        string $folder = '',
        string $templateFile = ''
    ) {
        $this->name   = $name;
        $this->kind   = $kind;
        $this->domain = $domain;
        $this->label  = $label;
        $this->pot    = $pot;
        $this->poDir  = $poDir;
        $this->poFile = $poFile;
        $this->folder = $folder;
        $this->templateFile = $templateFile;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** The shipped .pot, else the template made from the files. */
    public function potPath(): ?string
    {
        if ($this->pot !== null) {
            return $this->pot;
        }

        return $this->template() === 'generated' ? $this->templateFile : null;
    }

    /** 'shipped' when the source ships a .pot or an en_US .po, 'generated' when one was made from its files, else 'none'. */
    public function template(): string
    {
        if ($this->pot !== null || $this->shippedPoPath('en_US') !== null) {
            return 'shipped';
        }

        return $this->templateFile !== '' && is_file($this->templateFile) ? 'generated' : 'none';
    }

    /** A template can be made for a theme or plugin that ships none. */
    public function canMakeTemplate(): bool
    {
        return $this->folder !== '' && $this->templateFile !== '' && $this->template() !== 'shipped';
    }

    public function folder(): string
    {
        return $this->folder;
    }

    public function templateFile(): string
    {
        return $this->templateFile;
    }

    public function shippedPoPath(string $locale): ?string
    {
        if (!Locales::isCode($locale)) {
            return null;
        }
        $file = $this->poDir . $locale . '/' . $this->poFile;

        return is_file($file) ? $file : null;
    }

    public function originalsPath(string $locale): ?string
    {
        return $this->potPath() ?? $this->shippedPoPath($locale) ?? $this->shippedPoPath('en_US');
    }
}
