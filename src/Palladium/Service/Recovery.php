<?php

namespace Palladium\Service;

/**
 * Application logic for password reset handling
 */

use Palladium\Mapper as Mapper;
use Palladium\Entity as Entity;
use Palladium\Exception\IdentityNotFound;
use Palladium\Exception\IdentityNotVerified;

use Palladium\Contract\CanCreateMapper;
use Psr\Log\LoggerInterface;

class Recovery
{

    protected $mapperFactory;
    protected $logger;


    public function __construct(CanCreateMapper $mapperFactory, LoggerInterface $logger)
    {
        $this->mapperFactory = $mapperFactory;
        $this->logger = $logger;
    }


    public function markForReset(Entity\EmailIdentity $identity)
    {
        if ($identity->getStatus() === Entity\Identity::STATUS_NEW) {
            $this->logger->warning('account not verified', [
                'input' => [
                    'identifier' => $identifier,
                ],
                'account' => [
                    'user' => $identity->getUserId(),
                    'identity' => $identity->getId(),
                ],
            ]);

            throw new IdentityNotVerified;
        }

        $identity->generateToken();
        $identity->setTokenAction(Entity\Identity::ACTION_RESET);
        $identity->setTokenEndOfLife(time() + Entity\Identity::TOKEN_LIFESPAN);

        $mapper = $this->mapperFactory->create(Mapper\EmailIdentity::class);
        $mapper->store($identity);

        $this->logger->info('request password reset', [
            'input' => [
                'identifier' => $identifier,
            ],
        ]);
    }



    public function resetIdentityPassword(Entity\EmailIdentity $identity, $password)
    {
        $token = $identity->getToken();

        $identity->setPassword($password);
        $identity->clearToken();

        $mapper = $this->mapperFactory->create(Mapper\EmailIdentity::class);
        $mapper->store($identity);

        $this->logger->info('password reset successful', [
            'input' => [
                'token' => $token,
            ],
        ]);
    }
}
