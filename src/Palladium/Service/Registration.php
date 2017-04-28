<?php

namespace Palladium\Service;

/**
 * Code for creating new identities
 */

use Palladium\Mapper as Mapper;
use Palladium\Entity as Entity;
use Palladium\Exception\IdentityDuplicated;
use Palladium\Exception\UserNotFound;
use Palladium\Exception\IdentityNotFound;
use Palladium\Exception\TokenNotFound;

use Palladium\Contract\CanCreateMapper;
use Palladium\Contract\HasId;
use Psr\Log\LoggerInterface;

class Registration
{

    private $mapperFactory;
    private $logger;


    public function __construct(CanCreateMapper $mapperFactory, LoggerInterface $logger)
    {
        $this->mapperFactory = $mapperFactory;
        $this->logger = $logger;
    }


    public function createEmailIdentity($identifier, $password)
    {
        $identity = new Entity\EmailIdentity;

        $identity->setIdentifier($identifier);
        $identity->setPassword($password);

        $this->prepareNewIdentity($identity);

        $identity->validate();

        $mapper = $this->mapperFactory->create(Mapper\EmailIdentity::class);

        if ($mapper->exists($identity)) {
            $this->logger->warning('email already registered', [
                'input' => [
                    'identifier' => $identifier,
                ],
            ]);

            throw new IdentityDuplicated;
        }

        $mapper->store($identity);

        // process not ended, no point in logging

        return $identity;
    }


    public function prepareNewIdentity(Entity\EmailIdentity $identity)
    {
        $identity->setStatus(Entity\Identity::STATUS_NEW);

        $identity->generateToken();
        $identity->setTokenAction(Entity\Identity::ACTION_VERIFY);
        $identity->setTokenEndOfLife(time() + Entity\Identity::TOKEN_LIFESPAN);
    }


    public function bindIdentityToUser(Entity\Identity $identity, HasId $user)
    {
        if ($user->getId() === null) {
            throw new UserNotFound;
        }

        $identity->setUserId($user->getId());

        $mapper = $this->mapperFactory->create(Mapper\IdentityUser::class);
        $mapper->store($identity);

        $this->logger->info('new identity registered', [
            'input' => [
            ],
            'account' => [
                'user' => $identity->getUserId(),
                'identity' => $identity->getId(),
            ],
        ]);

        // @TODO: add mail later
    }


    public function verifyEmailIdentity($token)
    {
        $identity = new Entity\EmailIdentity;
        $this->retrieveIdenityByToken($identity, $token, Entity\Identity::ACTION_VERIFY);

        if ($identity->getId() === null) {
            $this->logger->warning('no identity with given verification token', [
                'input' => [
                    'token' => $token,
                ],
            ]);

            throw new TokenNotFound;
        }

        $identity->setStatus(Entity\Identity::STATUS_ACTIVE);
        $identity->clearToken();

        $mapper = $this->mapperFactory->create(Mapper\EmailIdentity::class);
        $mapper->store($identity);

        $this->logger->info('identity verified', [
            'input' => [
                'token' => $token,
            ],
            'account' => [
                'user' => $identity->getUserId(),
                'identity' => $identity->getId(),
            ],
        ]);
    }


    private function retrieveIdenityByToken(Entity\Identity $identity, $token, $action = Entity\Identity::ACTION_ANY)
    {
        $identity->setToken($token);
        $identity->setTokenAction($action);
        $identity->setTokenEndOfLife(time());

        $mapper = $this->mapperFactory->create(Mapper\Identity::class);
        $mapper->fetch($identity);

        return $identity;
    }

}
