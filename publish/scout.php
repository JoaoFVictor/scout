<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Hyperf\Scout\Provider\MeilisearchProvider;

use function Hyperf\Support\env;

return [
    'default' => env('SCOUT_ENGINE', 'meilisearch'),
    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],
    'prefix' => env('SCOUT_PREFIX', ''),
    'soft_delete' => true,
    'concurrency' => 100,
    'engine' => [
        'meilisearch' => [
            'driver' => MeilisearchProvider::class,
            'index' => null,
            'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
            'key' => env('MEILISEARCH_KEY'),
            'index-settings' => [
            ],
        ],
    ],
];
