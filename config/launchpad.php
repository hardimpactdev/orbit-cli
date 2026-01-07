<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Launchpad Configuration Path
    |--------------------------------------------------------------------------
    |
    | This value determines the path where Launchpad stores its configuration
    | and Docker compose files.
    |
    */

    'path' => env('LAUNCHPAD_PATH', getenv('HOME') ?: '/home/launchpad'.'/.config/launchpad'),

    /*
    |--------------------------------------------------------------------------
    | Supported PHP Versions
    |--------------------------------------------------------------------------
    |
    | The PHP versions that Launchpad supports. These correspond to the
    | FrankenPHP Docker images that will be pulled during init.
    |
    */

    'php_versions' => ['8.3', '8.4'],

];
