<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Inertia\Testing\AssertableInertia;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Acl\AclServiceProvider;
use Ipsocode\Acl\Http\UiAssets;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\Frontend;
use Ipsocode\Acl\Tests\TestCase;
use ValueError;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

#[WithConfig('acl.ui', UiTest::UI)]
class UiTest extends TestCase
{
    /**
     * The whole group: the app's config replaces the package's defaults one level deep.
     */
    public const UI = [
        'enabled' => true,
        'frontend' => 'bundled',
        'domain' => null,
        'path' => 'acl-admin',
        'middleware' => ['auth:web'],
        'permission' => 'acl.ui',
    ];

    public const ROUTES = [
        'enabled' => false,
        'prefix' => 'acl',
        'middleware' => ['api'],
        'name' => 'acl.',
        'permissions' => [],
    ];

    protected User $admin;

    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('acl.routes.enabled', false);
        $app->get('config')->set('inertia.testing.ensure_pages_exist', false);
    }

    protected function setUpInCoroutine(): void
    {
        (new AclSeeder)->run();

        $admins = AclTeam::create(['name' => 'Admins']);
        $this->admin = User::factory()->create();

        $admins->syncTeamRoles(['manager']);
        $admins->addUser($this->admin);

        $this->actingAs($this->admin);
    }

    #[WithConfig('acl.ui', [...self::UI, 'enabled' => false])]
    public function testTheUiIsOffUnlessEnabled(): void
    {
        $this->get('acl-admin')->assertNotFound();
        $this->getJson('acl-admin/acl-api/roles')->assertNotFound();
    }

    #[WithConfig('acl.ui', [...self::UI, 'frontend' => null])]
    public function testTheUiIsOffWithoutAFrontend(): void
    {
        $this->assertFalse(Config::uiEnabled());
        $this->get('acl-admin')->assertNotFound();
    }

    public function testTheBundledFrontendServesTheBladeShell(): void
    {
        $response = $this->get('acl-admin')
            ->assertOk()
            ->assertSee('<div id="acl"></div>', false)
            ->assertSee('vendor/acl/app.js', false);

        $this->assertSame([
            'path' => '/acl-admin',
            'workspaces' => false,
            'workspace' => null,
            'workspaceModel' => false,
            'workspaceParent' => null,
            'teamInherit' => 'inherit_id',
            'tenant' => false,
            'guards' => ['web'],
        ], $this->bootProps($response->getContent()));

        $this->get('acl-admin/teams')->assertOk();
    }

    #[WithConfig('acl.ui', [...self::UI, 'frontend' => 'react'])]
    public function testTheReactFrontendRendersAnInertiaPage(): void
    {
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get('acl-admin/roles')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('acl/index')
                ->where('path', '/acl-admin')
                ->where('workspaces', false)
                ->where('guards', ['web']));
    }

    #[WithConfig('acl.ui', [...self::UI, 'path' => '/admin/acl/'])]
    public function testThePathIsConfigurable(): void
    {
        $this->get('admin/acl/roles')->assertOk();
        $this->assertSame('/admin/acl', $this->bootProps($this->get('admin/acl/roles')->getContent())['path']);
        $this->getJson('admin/acl/acl-api/roles')->assertOk();
        $this->get('acl-admin')->assertNotFound();
    }

    #[WithConfig('acl.ui', [...self::UI, 'domain' => 'ui.example.test'])]
    public function testTheDomainIsConfigurable(): void
    {
        $this->get('http://ui.example.test/acl-admin')->assertOk();
        $this->get('http://localhost/acl-admin')->assertNotFound();
    }

    public function testTheInternalApiWorksWithTheSessionWhileThePublicApiIsOff(): void
    {
        $this->getJson('acl/roles')->assertNotFound();

        $this->getJson('acl-admin/acl-api/roles')->assertOk()->assertJsonCount(5, 'data');

        $this->postJson('acl-admin/acl-api/roles', ['name' => 'auditor'])->assertCreated();
        $this->getJson('acl-admin/acl-api/permissions')->assertOk();
        $this->getJson('acl-admin/acl-api/teams')->assertOk()->assertJsonCount(1, 'data');
    }

    #[WithConfig('acl.routes', [...self::ROUTES, 'permissions' => ['roles.index' => 'roles.list']])]
    public function testTheInternalApiKeepsThePerRoutePermission(): void
    {
        $this->getJson('acl-admin/acl-api/roles')->assertForbidden();
        $this->getJson('acl-admin/acl-api/permissions')->assertOk();
    }

    public function testTheUiRequiresItsPermission(): void
    {
        $viewers = AclTeam::create(['name' => 'Viewers']);
        $viewer = User::factory()->create();
        $viewers->syncTeamRoles(['viewer']);
        $viewers->addUser($viewer);

        $this->actingAs($viewer)->get('acl-admin')->assertForbidden();
        $this->actingAs($viewer)->getJson('acl-admin/acl-api/roles')->assertForbidden();
        $this->actingAs($this->admin)->get('acl-admin')->assertOk();
    }

    public function testAGuestIsNotLetIn(): void
    {
        $this->app->make('auth')->guard('web')->logout();

        $this->getJson('acl-admin/acl-api/roles')->assertUnauthorized();
    }

    public function testAnUnknownFrontendFailsLoudly(): void
    {
        config(['acl.ui.frontend' => 'vue']);

        $this->expectException(ValueError::class);

        Config::uiFrontend();
    }

    public function testTheFrontendIsTheDefault(): void
    {
        $this->assertSame(Frontend::Bundled, Config::uiFrontend());
    }

    public function testThePublishTagsAreRegistered(): void
    {
        $this->assertSame([public_path('vendor/acl')], array_values(ServiceProvider::pathsToPublish(AclServiceProvider::class, 'acl-assets')));

        $pages = ServiceProvider::pathsToPublish(AclServiceProvider::class, 'acl-react');
        $published = array_map(fn (string $target): string => substr($target, strlen(resource_path('js/pages/acl/'))), array_values($pages));

        // The pages and what they share; neither the bundle's entry nor the tests go to the application.
        $this->assertContains('index.tsx', $published);
        $this->assertContains('pages/RolesPage.tsx', $published);
        $this->assertNotContains('app.tsx', $published);
        $this->assertSame([], array_filter($published, fn (string $file): bool => str_contains($file, '.test.')));
    }

    public function testTheVersionHashesThePublishedBundle(): void
    {
        $directory = ParallelTesting::tempDir() . '/acl-assets';
        mkdir($directory, 0777, true);

        try {
            $this->assertSame('', UiAssets::from($directory)->version);

            file_put_contents("{$directory}/app.js", 'one');
            $js = UiAssets::from($directory)->version;
            $this->assertNotSame('', $js);

            // A change to the stylesheet alone changes the version too.
            file_put_contents("{$directory}/app.css", 'a');
            $both = UiAssets::from($directory)->version;
            file_put_contents("{$directory}/app.css", 'b');

            $this->assertNotSame($js, $both);
            $this->assertNotSame($both, UiAssets::from($directory)->version);
        } finally {
            array_map('unlink', glob("{$directory}/*"));
            rmdir($directory);
        }
    }

    public function testAnUnknownPathOfTheInternalApiIsNotFound(): void
    {
        $this->getJson('acl-admin/acl-api/typo')->assertNotFound();
        $this->get('acl-admin/acl-api')->assertNotFound();
        $this->get('acl-admin/typo')->assertOk()->assertSee('<div id="acl"></div>', false);
    }

    public function testSetupPublishesTheBundledAssets(): void
    {
        $this->runSetup(function (): void {
            $this->assertFileExists(public_path('vendor/acl/app.js'));
            $this->assertFileExists(public_path('vendor/acl/app.css'));
        });
    }

    #[WithConfig('acl.ui', [...self::UI, 'frontend' => 'react'])]
    public function testSetupLeavesTheAssetsOutForTheReactFrontend(): void
    {
        $this->runSetup(fn () => $this->assertFileDoesNotExist(public_path('vendor/acl/app.js')));
    }

    #[WithConfig('acl.ui', [...self::UI, 'enabled' => false])]
    public function testSetupLeavesTheAssetsOutWithoutTheUi(): void
    {
        $this->runSetup(fn () => $this->assertFileDoesNotExist(public_path('vendor/acl/app.js')));
    }

    /**
     * Get the props the shell hands the app: the JSON.parse() of a JavaScript string.
     *
     * @return array<string, mixed>
     */
    protected function bootProps(string $html): array
    {
        preg_match("/window\\.Acl = JSON\\.parse\\('(.*)'\\)/", $html, $match);

        return json_decode(json_decode('"' . $match[1] . '"'), true);
    }

    /**
     * Run acl:setup, declining its migrations, check what it published, and restore the skeleton.
     */
    protected function runSetup(callable $assert): void
    {
        $before = glob(database_path('migrations/*'));

        try {
            $this->artisan('acl:setup')->expectsConfirmation('Proceed with the migration creation?', 'no')->assertExitCode(0);

            $assert();
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
            array_map('unlink', glob(public_path('vendor/acl/*')) ?: []);
            @rmdir(public_path('vendor/acl'));
        }
    }
}
