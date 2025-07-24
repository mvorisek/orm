<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Cache\ConcurrentRegion;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\EntityPersister;

/**
 * Specific read-write entity persister
 */
class ReadWriteCachedEntityPersister extends AbstractEntityPersister
{
    public function __construct(EntityPersister $persister, ConcurrentRegion $region, EntityManagerInterface $em, ClassMetadata $class)
    {
        parent::__construct($persister, $region, $em, $class);
    }

    /**
     * {@inheritDoc}
     */
    public function afterTransactionComplete()
    {
        $isChanged = true;

        if (isset($this->queuedCache['update'])) {
            foreach ($this->queuedCache['update'] as $item) {
                $this->region->evict($item['key']);

                $isChanged = true;
            }
        }

        if (isset($this->queuedCache['delete'])) {
            foreach ($this->queuedCache['delete'] as $item) {
                $this->region->evict($item['key']);

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
        if (isset($this->queuedCache['update'])) {
            foreach ($this->queuedCache['update'] as $item) {
                $this->region->evict($item['key']);
            }
        }

        if (isset($this->queuedCache['delete'])) {
            foreach ($this->queuedCache['delete'] as $item) {
                $this->region->evict($item['key']);
            }
        }

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
        $lock    = $this->region->lock($key);
        $deleted = $this->persister->delete($entity);

        if ($deleted) {
            $this->region->evict($key);
        }

        if ($lock === null) {
            return $deleted;
        }

        $this->queuedCache['delete'][] = [
            'lock'   => $lock,
            'key'    => $key,
        ];

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function update($entity)
    {
        $key  = new EntityCacheKey($this->class->rootEntityName, $this->uow->getEntityIdentifier($entity));
        $lock = $this->region->lock($key);

        $this->persister->update($entity);

        if ($lock === null) {
            return;
        }

        $this->queuedCache['update'][] = [
            'lock'   => $lock,
            'key'    => $key,
        ];
    }
}
