# Roles and seeding

Describe your roles as enums that implement `RoleDefinition` and use `DefinesRole`. Generate the seeder with `php artisan acl:make-seeder` and return the roles from `definitions()`. The package creates no roles of its own: creating a team creates none.

The seeder is idempotent and prunes the global roles no definition names. It creates roles with no team, so every team can hold them. Use the `AssignsAcl` factory trait for `->inTeam($team)->role('editor')` and `->inTeam($team)->member()` states.
