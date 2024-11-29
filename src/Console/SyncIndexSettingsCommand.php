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

namespace Hyperf\Scout\Console;

use Exception;
use Hyperf\Command\Command;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Scout\EngineFactory;
use Hyperf\Utils\Str;
use Psr\Container\ContainerInterface;

class SyncIndexSettingsCommand extends Command
{
    private ConfigInterface $config;
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'scout:sync-index-settings';

    /**
     * The console command description.
     */
    protected string $description = 'Sync your configured index settings with your search engine (Meilisearch)';

    public function __construct(private readonly ContainerInterface $container, private readonly EngineFactory $engine)
    {
        parent::__construct();
        $this->config = $container->get(ConfigInterface::class);
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $driver = $this->config->get('scout.engine');

        if (! method_exists($this->engine, 'updateIndexSettings')) {
            return $this->error('The "'.$driver.'" engine does not support updating index settings.');
        }

        try {
            $indexes = (array) $this->config->get('scout.engine.'.$driver.'.index-settings', []);

            if (count($indexes)) {
                foreach ($indexes as $name => $settings) {
                    if (! is_array($settings)) {
                        $name = $settings;

                        $settings = [];
                    }

                    if (class_exists($name)) {
                        $model = new $name;
                    }

                    if (isset($model) &&
                        $this->config->get('scout.soft_delete', false) &&
                        in_array(\Hyperf\Database\Model\SoftDeletes::class, class_uses_recursive($model))) {
                        $settings['filterableAttributes'][] = '__soft_deleted';
                    }

                    $this->engine->updateIndexSettings($indexName = $this->indexName($name), $settings);

                    $this->info('Settings for the ['.$indexName.'] index synced successfully.');
                }
            } else {
                $this->info('No index settings found for the "'.$driver.'" engine.');
            }
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Get the fully-qualified index name for the given index.
     *
     * @param  string  $name
     * @return string
     */
    protected function indexName($name)
    {
        if (class_exists($name)) {
            return (new $name)->indexableAs();
        }

        $prefix = $this->config->get('scout.prefix');

        return ! Str::startsWith($name, $prefix) ? $prefix.$name : $name;
    }
}
