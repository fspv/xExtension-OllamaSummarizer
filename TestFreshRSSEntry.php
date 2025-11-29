<?php

declare(strict_types=1);

final class TestFreshRSSEntry extends FreshRSS_Entry
{
    private array $attributes = [];

    private bool $isUpdated = false;

    private ?FreshRSS_Feed $feed = null;

    private int $lastUserModified = 0;

    #[\Override]
    public function _attribute(string $key, $value = null): void
    {
        $this->attributes[$key] = $value;
    }

    #[\Override]
    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    #[\Override]
    public function attributeString(string $key): string
    {
        return (string) ($this->attributes[$key] ?? '');
    }

    #[\Override]
    public function attributeArray(string $key): array
    {
        return (array) ($this->attributes[$key] ?? []);
    }

    #[\Override]
    public function _isUpdated(bool $value): void
    {
        $this->isUpdated = $value;
    }

    #[\Override]
    public function isUpdated(): bool
    {
        return $this->isUpdated;
    }

    #[\Override]
    public function _feed(?FreshRSS_Feed $feed): void
    {
        $this->feed = $feed;
    }

    #[\Override]
    public function feed(): ?FreshRSS_Feed
    {
        return $this->feed;
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'guid' => $this->guid(),
            'title' => $this->title(),
            'author' => $this->authors(true),
            'content' => $this->content(),
            'link' => $this->link(),
            'date' => (int) $this->date(),
            'lastSeen' => $this->lastSeen(),
            'lastUserModified' => $this->lastUserModified,
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
