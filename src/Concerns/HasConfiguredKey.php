<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Concerns;

use Ipsocode\Acl\Support\KeyType;

/**
 * Gives a model the key type of the package's tables: an auto-increment id, a UUID or a ULID.
 */
trait HasConfiguredKey
{
    /**
     * Determine if the model uses unique ids.
     */
    public function usesUniqueIds(): bool
    {
        return KeyType::resolve() !== KeyType::Id;
    }

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return $this->usesUniqueIds() ? [$this->getKeyName()] : [];
    }

    /**
     * Generate a new key for the model.
     */
    public function newUniqueId(): ?string
    {
        return KeyType::resolve()->newId();
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getKeyType(): string
    {
        return $this->usesUniqueIds() ? 'string' : parent::getKeyType();
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        return ! $this->usesUniqueIds() && parent::getIncrementing();
    }
}
