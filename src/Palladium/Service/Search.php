<?php

namespace Palladium\Service;


/**
 * Class for finding indentities based on various conditions
 */

use Palladium\Mapper as Mapper;
use Palladium\Entity as Entity;
use Palladium\Exception\UserNotFound;
use Palladium\Exception\IdentityNotFound;

use Palladium\Contract\CanCreateMapper;
use Psr\Log\LoggerInterface;


class Search
{

    private $mapperFactory;
    private $logger;


    public function __construct(CanCreateMapper $mapperFactory, LoggerInterface $logger)
    {
        $this->mapperFactory = $mapperFactory;
        $this->logger = $logger;
    }


    /**
     * Locates identity based on ID
     *
     * @param int $identityId
     *
     * @return Palladium\Entity\Identity
     */
    public function findIdentityById($identityId)
    {
        $identity = new Entity\Identity;
        $identity->setId($identityId);

        $mapper = $this->mapperFactory->create(Mapper\Identity::class);
        $mapper->fetch($identity);

        if ($identity->getAccountId() === null) {
            $this->logger->warning('identity not found', [
                'input' => [
                    'id' => $identityId,
                ],
            ]);

            throw new IdentityNotFound;
        }

        return $identity;
    }


    /**
     * Locates identity based on email address
     *
     * @param string $emailAddress
     *
     * @return Palladium\Entity\EmailIdentity
     */
    public function findEmailIdentityByEmailAddress(string $emailAddress)
    {
        $identity = new Entity\EmailIdentity;
        $identity->setIdentifier($emailAddress);

        $mapper = $this->mapperFactory->create(Mapper\EmailIdentity::class);
        $mapper->fetch($identity);

        if ($identity->getId() === null) {
            $this->logger->warning('identity not found', [
                'input' => [
                    'identifier' => $emailAddress,
                ],
            ]);

            throw new IdentityNotFound;
        }

        return $identity;
    }


    /**
     * @param string $token
     * @param int $action
     *
     * @return Palladium\Entity\EmailIdentity
     */
    public function findEmailIdentityByToken(string $token, $action = Entity\Identity::ACTION_ANY)
    {
        $identity = new Entity\EmailIdentity;

        $identity->setToken($token);
        $identity->setTokenAction($action);
        $identity->setTokenEndOfLife(time());

        $mapper = $this->mapperFactory->create(Mapper\Identity::class);
        $mapper->fetch($identity);

        if ($identity->getId() === null) {
            $this->logger->warning('identity not found', [
                'input' => [
                    'token' => $token,
                ],
            ]);

            throw new IdentityNotFound;
        }

        return $identity;
    }


    /**
     * @param int $accountId
     * @param string $series
     *
     * @return Palladium\Entity\CookieIdentity
     */
    public function findCookieIdentity($accountId, $series)
    {
        $cookie = new Entity\CookieIdentity;
        $cookie->setStatus(Entity\Identity::STATUS_ACTIVE);
        $cookie->setAccountId($accountId);
        $cookie->setSeries($series);

        $mapper = $this->mapperFactory->create(Mapper\CookieIdentity::class);
        $mapper->fetch($cookie);

        return $cookie;
    }


    /**
     * @return Palladium\Entity\IdentityCollection
     */
    public function findIdentitiesByAccountId($accountId, $type = Entity\Identity::TYPE_ANY, $status = Entity\Identity::STATUS_ACTIVE)
    {
        $collection = new Entity\IdentityCollection;
        $collection->forAccountId($accountId);
        $collection->forType($type);

        return $this->fetchIdentitiesByStatus($collection, $status);
    }


    /**
     * @return Palladium\Entity\IdentityCollection
     */
    public function findIdentitiesByParentId($parentId, $status = Entity\Identity::STATUS_ACTIVE)
    {
        $collection = new Entity\IdentityCollection;
        $collection->forParentId($parentId);

        return $this->fetchIdentitiesByStatus($collection, $status);
    }


    /**
     * @return Palladium\Entity\IdentityCollection
     */
    private function fetchIdentitiesByStatus(Entity\IdentityCollection $collection, $status)
    {
        $collection->forStatus($status);

        $mapper = $this->mapperFactory->create(Mapper\IdentityCollection::class);
        $mapper->fetch($collection);

        return $collection;
    }
}
