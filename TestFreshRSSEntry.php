<?php

declare(strict_types=1);

class TestFreshRSSEntry extends FreshRSS_Entry
{
    private array $attributes = [];

    private bool $isUpdated = false;

    private ?FreshRSS_Feed $feed = null;

    public function _attribute(string $key, $value = null): void
    {
        $this->attributes[$key] = $value;
    }

    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function attributeString(string $key): string
    {
        return (string) ($this->attributes[$key] ?? '');
    }

    public function attributeArray(string $key): array
    {
        return (array) ($this->attributes[$key] ?? []);
    }

    public function _isUpdated(bool $value): void
    {
        $this->isUpdated = $value;
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
            'id' => (string) $this->id(),
            'guid' => $this->guid(),
            'title' => $this->title(),
            'author' => $this->author(),
            'content' => $this->content(),
            'link' => $this->link(),
            'date' => (int) $this->date(),
            'lastSeen' => $this->lastSeen(),
            'attributes' => $this->attributes,
            'isUpdated' => $this->isUpdated,
            'hash' => $this->hash(),
            'is_read' => $this->isRead(),
            'is_favorite' => $this->isFavorite(),
            'id_feed' => $this->feed()?->id() ?? 0,
            'feed' => $this->feed(),
            'tags' => '',
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
