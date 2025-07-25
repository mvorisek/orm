<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Cache\ConcurrentRegion;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use ReflectionMethod;

use function array_map;
use function count;

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
        $keys  = array_map(function ($entity) {
            return new EntityCacheKey($this->class->rootEntityName, $this->uow->getEntityIdentifier($entity));
        }, $entities);
        $locks = array_map(function ($key) {
            return $this->region->lock($key);
        }, $keys);

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

        foreach ($keys as $k => $key) {
            $lock = $locks[$k];
            if ($lock !== null) {
                $this->queuedCache['delete'][] = [
                    'lock'   => $lock,
                    'key'    => $key,
                ];
            }
        }

        return $deletedCount;
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
