<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Support;

enum Frontend: string
{
    /**
     * The package's own React app, prebuilt and served by a Blade shell.
     */
    case Bundled = 'bundled';

    /**
     * The same pages as Inertia pages, published into the application.
     */
    case React = 'react';
}
