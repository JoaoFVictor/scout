<?php

declare(strict_types=1);

namespace Hyperf\Scout\Engine;

use Hyperf\Database\Model\Model;
use Hyperf\Collection\Collection;
use Hyperf\Collection\LazyCollection;
use Hyperf\Database\Model\Collection as ModelCollection;
use Hyperf\Scout\Builder;
use Hyperf\Scout\Engine\Engine;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Search\SearchResult;

class MeilisearchEngine extends Engine
{
    public function __construct(protected MeilisearchClient $meilisearch, protected bool $softDelete = false)
    {
    }

    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->meilisearch->index($models->first()->searchableAs());

        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $objects = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            return array_merge(
                $searchableData,
                $model->scoutMetadata(),
                [$model->getScoutKeyName() => $model->getScoutKey()],
            );
        })->filter()->values()->all();

        if (! empty($objects)) {
            $index->addDocuments($objects, $models->first()->getScoutKeyName());
        }
    }

    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }
    
        $index = $this->meilisearch->index($models->first()->searchableAs());
    
        $keys = $models->map(function ($model) {
            return $model->getScoutKey();
        });
    
        $index->deleteDocuments($keys->toArray());
    }
    

    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'filter' => $this->filters($builder),
            'hitsPerPage' => $builder->limit,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]));
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, array_filter([
            'filter' => $this->filters($builder),
            'hitsPerPage' => (int) $perPage,
            'page' => $page,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]));
    }

    protected function performSearch(Builder $builder, array $searchParams = [])
    {
        $meilisearch = $this->meilisearch->index($builder->index ?: $builder->model->searchableAs());

        if (array_key_exists('attributesToRetrieve', $searchParams)) {
            $searchParams['attributesToRetrieve'] = array_merge(
                [$builder->model->getScoutKeyName()],
                $searchParams['attributesToRetrieve'],
            );
        }

        if ($builder->callback) {
            $result = call_user_func(
                $builder->callback,
                $meilisearch,
                $builder->query,
                $searchParams
            );

            $searchResultClass = class_exists(SearchResult::class)
                ? SearchResult::class
                : \Meilisearch\Search\SearchResult;

            return $result instanceof $searchResultClass ? $result->getRaw() : $result;
        }

        return $meilisearch->rawSearch($builder->query, $searchParams);
    }

    protected function filters(Builder $builder)
    {
        $filters = collect($builder->wheres)->map(function ($value, $key) {
            if (is_bool($value)) {
                return sprintf('%s=%s', $key, $value ? 'true' : 'false');
            }

            return is_numeric($value)
                ? sprintf('%s=%s', $key, $value)
                : sprintf('%s="%s"', $key, $value);
        });

        $whereInOperators = [
            'whereIns' => 'IN',
            'whereNotIns' => 'NOT IN',
        ];

        foreach ($whereInOperators as $property => $operator) {
            if (property_exists($builder, $property)) {
                foreach ($builder->{$property} as $key => $values) {
                    $filters->push(sprintf('%s %s [%s]', $key, $operator, collect($values)->map(function ($value) {
                        if (is_bool($value)) {
                            return sprintf('%s', $value ? 'true' : 'false');
                        }

                        return filter_var($value, FILTER_VALIDATE_INT) !== false
                            ? sprintf('%s', $value)
                            : sprintf('"%s"', $value);
                    })->values()->implode(', ')));
                }
            }
        }

        return $filters->values()->implode(' AND ');
    }

    protected function buildSortFromOrderByClauses(Builder $builder): array
    {
        return collect($builder->orders)->map(function (array $order) {
            return $order['column'].':'.$order['direction'];
        })->toArray();
    }

    public function mapIds($results): Collection
    {
        if (0 === count($results['hits'])) {
            return collect();
        }

        $hits = collect($results['hits']);

        $key = key($hits->first());

        return $hits->pluck($key)->values();
    }

    public function mapIdsFrom($results, $key)
    {
        return count($results['hits']) === 0
                ? collect()
                : collect($results['hits'])->pluck($key)->values();
    }

    public function keys(Builder $builder): Collection
    {
        $scoutKey = $builder->model->getScoutKeyName();

        return $this->mapIdsFrom($this->search($builder), $scoutKey);
    }

    public function map(Builder $builder, $results, Model $model): ModelCollection
    {
        if (is_null($results) || 0 === count($results['hits'])) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck($model->getScoutKeyName())->values()->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds(
            $builder, $objectIds
        )->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->map(function ($model) use ($results, $objectIdPositions) {
            $result = $results['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

            foreach ($result as $key => $value) {
                if (substr($key, 0, 1) === '_') {
                    $model->withScoutMetadata($key, $value);
                }
            }

            return $model;
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    public function lazyMap(Builder $builder, $results, $model)
    {
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits'])->pluck($model->getScoutKeyName())->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds(
            $builder, $objectIds
        )->cursor()->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->map(function ($model) use ($results, $objectIdPositions) {
            $result = $results['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

            foreach ($result as $key => $value) {
                if (substr($key, 0, 1) === '_') {
                    $model->withScoutMetadata($key, $value);
                }
            }

            return $model;
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    public function getTotalCount($results): int
    {
        return $results['totalHits'];
    }

    public function flush($model): void
    {
        $index = $this->meilisearch->index($model->searchableAs());

        $index->deleteAllDocuments();
    }

    public function createIndex($name, array $options = [])
    {
        return $this->meilisearch->createIndex($name, $options);
    }

    public function updateIndexSettings($name, array $options = [])
    {
        return $this->meilisearch->index($name)->updateSettings($options);
    }

    public function deleteIndex($name)
    {
        return $this->meilisearch->deleteIndex($name);
    }

    public function deleteAllIndexes()
    {
        $tasks = [];
        $limit = 1000000;

        $query = new IndexesQuery();
        $query->setLimit($limit);

        $indexes = $this->meilisearch->getIndexes($query);

        foreach ($indexes->getResults() as $index) {
            $tasks[] = $index->delete();
        }

        return $tasks;
    }

    protected function usesSoftDelete($model): bool
    {
        return in_array(\Hyperf\Database\Model\SoftDeletes::class, class_uses_recursive($model));
    }

    public function __call($method, $parameters)
    {
        return $this->meilisearch->$method(...$parameters);
    }
}
