<?php

declare(strict_types=1);

class TestFreshRSSEntry extends FreshRSS_Entry
{
    private array $attributes = [];
    private bool $isUpdated = false;
    private ?FreshRSS_Feed $feed = null;

    public function _attribute(string $name, $value = null): void
    {
        $this->attributes[$name] = $value;
    }

    public function hasAttribute(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function attributeString(string $name): string
    {
        return (string)($this->attributes[$name] ?? '');
    }

    public function attributeArray(string $name): array
    {
        return (array)($this->attributes[$name] ?? []);
    }

    public function _isUpdated(bool $isUpdated): void
    {
        $this->isUpdated = $isUpdated;
    }

    public function isUpdated(): bool
    {
        return $this->isUpdated;
    }

    public function _feed(?FreshRSS_Feed $feed): void
    {
        $this->feed = $feed;
    }

    public function feed(): ?FreshRSS_Feed
    {
        return $this->feed;
    }

    public function toArray(): array
    {
        return [
            'link' => $this->link(),
            'title' => $this->title(),
            'guid' => $this->guid(),
            'content' => $this->content(),
            'tags' => $this->tags(),
            'attributes' => $this->attributes,
            'isUpdated' => $this->isUpdated
        ];
    }
}

class TestFreshRSSFeed
{
    private ?string $pathEntries = null;

    public function pathEntries(): ?string
    {
        return $this->pathEntries;
    }

    public function setPathEntries(?string $pathEntries): void
    {
        $this->pathEntries = $pathEntries;
    }
} 