<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Cache\EntityCacheKey;

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
        $key     = new EntityCacheKey($this->class->rootEntityName, $this->uow->getEntityIdentifier($entity));
        $deleted = $this->persister->delete($entity);

        if ($deleted) {
            $this->region->evict($key);
        }

        $this->queuedCache['delete'][] = $key;

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function update($entity)
    {
        $this->persister->update($entity);

        $this->queuedCache['update'][] = $entity;
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
