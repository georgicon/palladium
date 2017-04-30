<?php

namespace Palladium\Mapper;

/**
 * SQL code repsonsible for locating all of the identities, that have been associated
 * to a given user and discarding them in bulk.
 * Used mostly in case of password reset or, if cookie has been compromised.
 */


use Palladium\Component\SqlMapper;
use Palladium\Entity as Entity;

class IdentityCollection extends SqlMapper
{

    /**
     * @param Entity\IdentityCollection $collection
     */
    public function store(Entity\IdentityCollection $collection)
    {
        if ($collection->getAccountId() !== null) {
            $this->updateStatus($collection);
        }
    }


    private function updateStatus(Entity\IdentityCollection $collection)
    {
        $sql = "UPDATE {$this->table}
                   SET status = :status
                 WHERE identity_id = :id";
        $statement = $this->connection->prepare($sql);

        foreach ($collection as $entity) {
            $statement->bindValue(':id', $entity->getId());
            $statement->bindValue(':status', $entity->getStatus());
            $statement->execute();
        }
    }


    /**
     * @param Entity\IdentityCollection $collection
     */
    public function fetch(Entity\IdentityCollection $collection)
    {
        $sql = "SELECT identity_id  AS id
                  FROM {$this->table}
                 WHERE status = :status
                   AND account_id = :account
                   AND type = :type";

        $statement = $this->connection->prepare($sql);

        $statement->bindValue(':account', $collection->getAccountId());
        $statement->bindValue(':status', $collection->getStatus());
        $statement->bindValue(':type', $collection->getType());

        $statement->execute();

        foreach ($statement as $parameters) {
            $collection->addBlueprint($parameters);
        }
    }
}
