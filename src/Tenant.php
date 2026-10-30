<?php

declare(strict_types=1);

namespace Ipsocode\Acl;

use Closure;
use Hypervel\Context\CoroutineContext;

/**
 * The tenant the current request or job acts in, kept in the coroutine's own context so it
 * never reaches a concurrent request. The tenant scopes and the permission cache read it.
 */
class Tenant
{
    public const CONTEXT_KEY = '__acl.tenant';

    /**
     * Get the current tenant, or null when none is set.
     */
    public static function id(): int|string|null
    {
        return CoroutineContext::get(self::CONTEXT_KEY);
    }

    /**
     * Determine whether a tenant is set.
     */
    public static function check(): bool
    {
        return static::id() !== null;
    }

    /**
     * Set the current tenant for this coroutine, or clear it with null.
     */
    public static function set(int|string|null $id): void
    {
        $id === null || $id === ''
            ? CoroutineContext::forget(self::CONTEXT_KEY)
            : CoroutineContext::set(self::CONTEXT_KEY, $id);
    }

    /**
     * Run the callback as a tenant, restoring the previous one afterwards.
     *
     * A null tenant, as for a team without one, runs the callback as it is.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public static function run(int|string|null $id, Closure $callback): mixed
    {
        if ($id === null) {
            return $callback();
        }

        $previous = static::id();

        static::set($id);

        try {
            return $callback();
        } finally {
            static::set($previous);
        }
    }
}
