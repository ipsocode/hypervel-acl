<?php

declare(strict_types=1);

use Ipsocode\Acl\Models\AclTeam;

// The package requires teams, so the Testbench CLI boots it with them on. The file replaces the
// framework's own, hence the merge.
return array_replace_recursive(require dirname(__DIR__, 2) . '/vendor/hypervel/components/src/permission/config/permission.php', [
    'teams' => true,
    'models' => ['team' => AclTeam::class],
]);
