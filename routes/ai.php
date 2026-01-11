<?php

declare(strict_types=1);

use App\Mcp\Servers\LaunchpadServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Routes
|--------------------------------------------------------------------------
|
| Register MCP servers for AI tool integration. The 'launchpad' server
| provides access to Docker infrastructure, site management, and
| environment configuration.
|
| Usage: launchpad mcp:start launchpad
|
*/

Mcp::local('launchpad', LaunchpadServer::class);
