<?php

namespace Palladium\Service;

/**
 * Code for creating new identities
 */

use Palladium\Mapper as Mapper;
use Palladium\Entity as Entity;
use Palladium\Exception\IdentityDuplicated;

use Palladium\Contract\CanCreateMapper;
use Palladium\Contract\HasId;
use Psr\Log\LoggerInterface;

class Registration
{
    const DEFAULT_TOKEN_LIFESPAN = 28800; // 8 hours

    private $mapperFactory;
    private $logger;

    private $tokenLifespan;

    public function __construct(CanCreateMapper $mapperFactory, LoggerInterface $logger, $tokenLifespan = self::DEFAULT_TOKEN_LIFESPAN)
    {
        $this->mapperFactory = $mapperFactory;
        $this->logger = $logger;
        $this->tokenLifespan = $tokenLifespan;
    }


    /**
     * @param string $emailAddress
     * @param string $password
     *
     * @return Palladium\Entity\EmailIdentity
     */
    public function createEmailIdentity(string $emailAddress, string $password)
    {
        $identity = new Entity\EmailIdentity;

        $identity->setEmailAddress($emailAddress);
        $identity->setPassword($password);

        $this->prepareNewIdentity($identity);

        $mapper = $this->mapperFactory->create(Mapper\EmailIdentity::class);

        if ($mapper->exists($identity)) {
            $this->logger->notice('email already registered', [
                'input' => [
                    'email' => $emailAddress,
                ],
            ]);

            throw new IdentityDuplicated;
        }

        $mapper->store($identity);

        return $identity;
    }


    private function prepareNewIdentity(Entity\EmailIdentity $identity)
    {
        $identity->setStatus(Entity\Identity::STATUS_NEW);

        $identity->generateToken();
        $identity->setTokenAction(Entity\Identity::ACTION_VERIFY);
        $identity->setTokenEndOfLife(time() + $this->tokenLifespan);
    }


    public function bindAccountToIdentity(int $accountId, Entity\Identity $identity)
    {
        $identity->setAccountId($accountId);

        $mapper = $this->mapperFactory->create(Mapper\IdentityAccount::class);
        $mapper->store($identity);

        $this->logger->info('new identity registered', [
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);
    }


    public function verifyEmailIdentity(Entity\EmailIdentity $identity)
    {
        $identity->setStatus(Entity\Identity::STATUS_ACTIVE);
        $identity->clearToken();

        $mapper = $this->mapperFactory->create(Mapper\EmailIdentity::class);
        $mapper->store($identity);

        $this->logger->info('identity verified', [
            'input' => [
                'token' => $identity->getToken(),
            ],
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);
    }
}
