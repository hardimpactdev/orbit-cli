<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends ApiController
{
    /**
     * List enabled services.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->executeCommand('service:list'));
    }

    /**
     * List available services.
     */
    public function available(): JsonResponse
    {
        return response()->json($this->executeCommand('service:list', ['available' => true]));
    }

    /**
     * Enable a service.
     */
    public function enable(string $service): JsonResponse
    {
        return response()->json($this->executeCommand('service:enable '.escapeshellarg($service)));
    }

    /**
     * Disable a service.
     */
    public function disable(string $service): JsonResponse
    {
        return response()->json($this->executeCommand('service:disable '.escapeshellarg($service)));
    }

    /**
     * Update service config.
     */
    public function updateConfig(Request $request, string $service): JsonResponse
    {
        $config = $request->input('config', []);

        $cmd = 'service:configure '.escapeshellarg($service);
        foreach ($config as $key => $value) {
            $cmd .= ' --set='.escapeshellarg("{$key}={$value}");
        }

        return response()->json($this->executeCommand($cmd));
    }

    /**
     * Get service template details.
     */
    public function info(string $service): JsonResponse
    {
        return response()->json($this->executeCommand('service:info '.escapeshellarg($service)));
    }
}
