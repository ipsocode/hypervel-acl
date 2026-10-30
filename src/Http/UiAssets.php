<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http;

class UiAssets
{
    /**
     * @param string $version The hash of the bundled script, read once at boot, that busts the browser's copy after an update
     */
    public function __construct(public readonly string $version)
    {
    }
}
