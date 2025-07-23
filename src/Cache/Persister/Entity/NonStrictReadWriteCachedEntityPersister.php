<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use ReflectionMethod;

use function array_map;
use function count;
use function get_class;

/**
 * Specific non-strict read/write cached entity persister
 */
class NonStrictReadWriteCachedEntityPersister extends AbstractEntityPersister
{
    /**
     * {@inheritDoc}
     */
    public function afterTransactionComplete()
    {
        $isChanged = false;

        if (isset($this->queuedCache['insert'])) {
            foreach ($this->queuedCache['insert'] as $entity) {
                $isChanged = $this->updateCache($entity, $isChanged);
            }
        }

        if (isset($this->queuedCache['update'])) {
            foreach ($this->queuedCache['update'] as $entity) {
                $isChanged = $this->updateCache($entity, $isChanged);
            }
        }

        if (isset($this->queuedCache['delete'])) {
            foreach ($this->queuedCache['delete'] as $key) {
                $this->region->evict($key);

                $isChanged = true;
            }
        }

        if ($isChanged) {
            $this->timestampRegion->update($this->timestampKey);
        }

        $this->queuedCache = [];
    }

    /**
     * {@inheritDoc}
     */
    public function afterTransactionRolledBack()
    {
        $this->queuedCache = [];
    }

    /**
     * {@inheritDoc}
     */
    public function delete($entity)
    {
        return $this->deleteMulti([$entity]) === 1;
    }

    /**
     * @param non-empty-list<object> $entities The entities to delete.
     *
     * @return int Number of entities that got deleted from the database.
     */
    public function deleteMulti(array $entities)
    {
        $keys = array_map(function ($entity) {
            return new EntityCacheKey($this->class->rootEntityName, $this->uow->getEntityIdentifier($entity));
        }, $entities);

        // EntityPersister::delete() and EntityPersister::deleteMulti() methods must be not overriden or always overriden at the same time
        if ($this->persister instanceof BasicEntityPersister && (new ReflectionMethod($this->persister, 'delete'))->getDeclaringClass()->getName() === (new ReflectionMethod($this->persister, 'deleteMulti'))->getDeclaringClass()->getName()) {
            $deletedCount = $this->persister->deleteMulti($entities);
        } else {
            $deletedCount = 0;
            foreach ($entities as $entity) {
                if ($this->persister->delete($entity)) {
                    ++$deletedCount;
                }
            }
        }

        if ($deletedCount === count($entities)) {
            foreach ($keys as $key) {
                $this->region->evict($key);
            }
        }

        foreach ($keys as $key) {
            $this->queuedCache['delete'][] = $key;
        }

        return $deletedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function update($entity)
    {
        $this->updateMulti([$entity]);
    }

    /**
     * @param non-empty-list<object> $entities The entities to update.
     *
     * @return void
     */
    public function updateMulti(array $entities)
    {
        // EntityPersister::update() and EntityPersister::updateMulti() methods must be not overriden or always overriden at the same time
        if ($this->persister instanceof BasicEntityPersister && (new ReflectionMethod($this->persister, 'update'))->getDeclaringClass()->getName() === (new ReflectionMethod($this->persister, 'updateMulti'))->getDeclaringClass()->getName()) {
            $this->persister->updateMulti($entities);
        } else {
            foreach ($entities as $entity) {
                $this->persister->update($entity);
            }
        }

        foreach ($entities as $entity) {
            $this->queuedCache['update'][] = $entity;
        }
    }

    /** @param object $entity */
    private function updateCache($entity, bool $isChanged): bool
    {
        $class     = $this->metadataFactory->getMetadataFor(get_class($entity));
        $key       = new EntityCacheKey($class->rootEntityName, $this->uow->getEntityIdentifier($entity));
        $entry     = $this->hydrator->buildCacheEntry($class, $key, $entity);
        $cached    = $this->region->put($key, $entry);
        $isChanged = $isChanged || $cached;

        if ($this->cacheLogger && $cached) {
            $this->cacheLogger->entityCachePut($this->regionName, $key);
        }

        return $isChanged;
    }
}
