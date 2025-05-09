<?php

declare(strict_types=1);

interface EntryInterface
{
    public function link(): string;
    public function title(): string;
    public function guid(): string;
    public function content(): string;
    public function tags(): array;
    public function hasAttribute(string $name): bool;
    public function attributeString(string $name): string;
    public function attributeArray(string $name): array;
    public function isUpdated(): bool;
    public function feed(): ?object;
    public function toArray(): array;
} 