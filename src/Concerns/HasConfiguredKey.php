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
     * The key type, resolved once per model class because it cannot change while a worker runs.
     */
    protected static ?KeyType $configuredKeyType = null;

    /**
     * Get the key type of the package's tables.
     */
    protected function configuredKeyType(): KeyType
    {
        return static::$configuredKeyType ??= KeyType::resolve();
    }

    /**
     * Forget the resolved key type.
     *
     * Tests only. A test that changes the configured key type or the role model's key traits would otherwise leave the worker with the old one.
     */
    public static function flushConfiguredKeyType(): void
    {
        static::$configuredKeyType = null;
    }

    /**
     * Determine if the model uses unique ids.
     */
    public function usesUniqueIds(): bool
    {
        return $this->configuredKeyType() !== KeyType::Id;
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
        return $this->configuredKeyType()->newId();
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
