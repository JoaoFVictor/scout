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

namespace Hyperf\Scout\Provider;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Scout\Engine\Engine;
use Hyperf\Scout\Engine\MeilisearchEngine;
use Psr\Container\ContainerInterface;

class MeilisearchProvider implements ProviderInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function make(string $name): Engine
    {
        $config = $this->container->get(ConfigInterface::class);
        $client = new Client($config->get("scout.engine.{$name}.host"), $config->get("scout.engine.{$name}.key"));

        return new MeilisearchEngine($client, $config->get("scout.soft_delete"));
    }
}
