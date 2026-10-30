<?php

declare(strict_types=1);

namespace Ipsocode\Acl;

use Closure;
use Hypervel\Context\CoroutineContext;

/**
 * The workspace the current request or job acts in, kept in the coroutine's own context so it
 * never reaches a concurrent request. With acl.workspace on, it is the framework's permission
 * partition; without one set, the home workspace, which holds every team's own roles, is.
 */
class Workspace
{
    public const CONTEXT_KEY = '__acl.workspace';

    /**
     * The partition of the global roles and of every team's own roles: the default workspace.
     */
    public const HOME = 0;

    /**
     * Get the current workspace, or the home workspace when none is set.
     */
    public static function id(): int|string
    {
        return CoroutineContext::get(self::CONTEXT_KEY) ?? self::HOME;
    }

    /**
     * Set the current workspace for this coroutine, or return to the home workspace with null.
     */
    public static function set(int|string|null $id): void
    {
        $id === null || $id === ''
            ? CoroutineContext::forget(self::CONTEXT_KEY)
            : CoroutineContext::set(self::CONTEXT_KEY, $id);
    }

    /**
     * Run the callback in a workspace, restoring the previous one afterwards.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public static function run(int|string $id, Closure $callback): mixed
    {
        $previous = CoroutineContext::get(self::CONTEXT_KEY);

        static::set($id);

        try {
            return $callback();
        } finally {
            static::set($previous);
        }
    }
}
