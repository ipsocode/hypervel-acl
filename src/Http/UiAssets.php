<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http;

class UiAssets
{
    /**
     * @param string $version The hash of the published bundle, which busts the browser's copy when the files change
     */
    public function __construct(public readonly string $version)
    {
    }

    /**
     * Read the version from the bundle published in a directory: empty until it is published.
     *
     * Called at boot, before the workers fork, so no request reads the files.
     */
    public static function from(string $directory): self
    {
        $hashes = [];

        foreach (['app.js', 'app.css'] as $file) {
            if (is_file("{$directory}/{$file}")) {
                $hashes[] = hash_file('xxh128', "{$directory}/{$file}");
            }
        }

        return new self($hashes === [] ? '' : hash('xxh128', implode($hashes)));
    }
}
