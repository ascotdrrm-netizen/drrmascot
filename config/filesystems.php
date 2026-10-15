<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // 'serve' left false deliberately: this disk holds requisition letters and other
            // private files that must only be reachable through an authenticated controller
            // route, not Laravel's auto-registered unauthenticated /storage/{path} route.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Uploaded record files when Supabase Storage is not set up: this
        // server's private folder (never served directly; see FileController).
        'records' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Supabase Storage through its S3-compatible API: the private bucket
        // "drrms" (Storage > S3 Configuration in the Supabase dashboard).
        's3' => [
            'driver' => 's3',
            'key' => env('SUPABASE_S3_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('SUPABASE_S3_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('SUPABASE_S3_REGION', env('AWS_DEFAULT_REGION', 'ap-southeast-1')),
            'bucket' => env('SUPABASE_S3_BUCKET', env('AWS_BUCKET', 'drrms')),
            'endpoint' => env('SUPABASE_S3_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

    ],

    // Where every uploaded record file goes (App\Support\Files): "s3" for
    // Supabase Storage, "records" for this server's private folder.
    'records_disk' => env('FILES_DISK', 'records'),

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
