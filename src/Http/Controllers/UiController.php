<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Controllers;

use Hypervel\Contracts\View\View;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Response;
use Hypervel\Routing\Controller;
use Ipsocode\Acl\Http\UiAssets;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\Frontend;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspace;

class UiController extends Controller
{
    /**
     * Display the management UI in the configured frontend.
     */
    public function index(UiAssets $assets): Response|View
    {
        $props = [
            'path' => '/' . Config::uiPath(),
            'workspaces' => Workspaces::enabled(),
            'workspace' => Workspaces::enabled() ? Workspace::id() : null,
            'workspaceModel' => Workspaces::enabled() && Workspaces::model() !== null,
            'workspaceParent' => Workspaces::enabled() ? Workspaces::parentColumn() : null,
            'teamInherit' => Config::teamsInheritKey(),
            'tenant' => Teams::tenantColumn() !== null,
            'guards' => Config::guards(),
        ];

        return match (Config::uiFrontend()) {
            Frontend::React => Inertia::render('acl/index', $props),
            default => view('acl::app', ['props' => $props, 'version' => $assets->version]),
        };
    }
}
