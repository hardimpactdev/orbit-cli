<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Mcp\OrbitServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Routes
|--------------------------------------------------------------------------
|
| Register MCP servers for AI tool integration. The 'orbit' server
| provides access to Docker infrastructure, site management, and
| environment configuration.
|
| The MCP server implementation lives in orbit-core and is imported here.
|
| Usage: orbit mcp:start orbit
|
*/

Mcp::local('orbit', OrbitServer::class);
