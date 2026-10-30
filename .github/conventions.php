<?php

declare(strict_types=1);

/*
 * This package's settings for .github/scripts/conventions.php, the conventions check every
 * ipsocode/hypervel-* package imports. initial.yml runs it ahead of the test jobs, and
 * `composer conventions` locally. The script's header lists the rules.
 */

return [
    // The name this package writes under wherever the application writes too:
    // __acl.* context keys, acl:* commands and store keys, acl-* publish
    // tags, ACL_* env vars and config/acl.php.
    'slug' => 'acl',

    'excluded' => [],

    'banned_namespaces' => [],

    'banned_functions' => [],

    'coverage_excludes' => [],

    'allowed' => [],
];
