<?php

namespace Palladium\Service;

/**
 * Retrieval and handling of identities for registered users
 */

use Palladium\Mapper as Mapper;
use Palladium\Entity as Entity;
use Palladium\Exception\PasswordNotMatch;
use Palladium\Exception\CompromisedCookie;
use Palladium\Exception\DenialOfServiceAttempt;
use Palladium\Exception\IdentityExpired;
use Palladium\Contract\CanCreateMapper;
use Psr\Log\LoggerInterface;

class Identification
{

    private $mapperFactory;
    private $logger;


    public function __construct(CanCreateMapper $mapperFactory, LoggerInterface $logger)
    {
        $this->mapperFactory = $mapperFactory;
        $this->logger = $logger;
    }


    /**
     * @param string $password
     */
    public function loginWithPassword(Entity\EmailIdentity $identity, $password)
    {
        if ($identity->matchPassword($password) === false) {
            $this->logWrongPasswordWarning($identity, [
                'identifier' => $identity->getIdentifier(),
                'key' => md5($password),
            ]);

            throw new PasswordNotMatch;
        }

        $this->registerUsageOfIdentity($identity);
        $cookie = $this->createCookieIdentity($identity);

        $this->logger->info('login successful', [
            'input' => [
                'identifier' => $identity->getIdentifier(),
            ],
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);

        return $cookie;
    }


    private function registerUsageOfIdentity(Entity\Identity $identity)
    {
        $identity->setLastUsed(time());

        $mapper = $this->mapperFactory->create(Mapper\Identity::class);
        $mapper->store($identity);
    }


    private function createCookieIdentity(Entity\EmailIdentity $identity)
    {
        $cookie = new Entity\CookieIdentity;
        $mapper = $this->mapperFactory->create(Mapper\CookieIdentity::class);

        $cookie->setAccountId($identity->getAccountId());
        $cookie->generateNewSeries();

        $cookie->generateNewKey();
        $cookie->setStatus(Entity\Identity::STATUS_ACTIVE);
        $cookie->setExpiresOn(time() + Entity\Identity::COOKIE_LIFESPAN);

        $mapper->store($cookie);

        return $cookie;
    }


    /**
     * @param string @key
     */
    public function loginWithCookie(Entity\CookieIdentity $identity, $key)
    {
        if ($identity->getId() === null) {
            $this->logCookieError($identity, 'denial of service');
            throw new DenialOfServiceAttempt;
        }

        $mapper = $this->mapperFactory->create(Mapper\CookieIdentity::class);

        if ($identity->getExpiresOn() < time()) {
            $identity->setStatus(Entity\Identity::STATUS_EXPIRED);
            $mapper->store($identity);
            $this->logger->info('cookie expired', [
                'input' => [
                    'account' => $identity->getAccountId(),
                    'series' => $identity->getSeries(),
                    'key' => $identity->getKey(),
                ],
                'user' => [
                    'account' => $identity->getAccountId(),
                    'identity' => $identity->getId(),
                ],
            ]);

            throw new IdentityExpired;
        }

        $this->checkCookieKey($identity, $key);

        $identity->generateNewKey();
        $identity->setLastUsed(time());
        $identity->setExpiresOn(time() + Entity\Identity::COOKIE_LIFESPAN);

        $mapper->store($identity);

        $this->logger->info('cookie updated', [
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);

        return $identity;
    }


    /**
     * @param string $key
     */
    public function logout(Entity\CookieIdentity $identity, $key)
    {
        if ($identity->getId() === null) {
            $this->logCookieError($identity, 'denial of service');
            throw new DenialOfServiceAttempt;
        }

        $this->checkCookieKey($identity, $key);
        $identity->setStatus(Entity\Identity::STATUS_DISCARDED);

        $mapper = $this->mapperFactory->create(Mapper\CookieIdentity::class);
        $mapper->store($identity);

        $this->logger->info('logout successful', [
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);

    }


    /**
     * Verify that the cookie based identity matches the key and,
     * if verification is failed, disable this given identity
     *
     * @param string $key
     * @throws \Palladium\Exception\CompromisedCookie if key does not match
     */
    private function checkCookieKey(Entity\CookieIdentity $identity, $key)
    {
        if ($identity->matchKey($key) === true) {
            return;
        }

        $identity->setStatus(Entity\Identity::STATUS_BLOCKED);

        $mapper = $this->mapperFactory->create(Mapper\CookieIdentity::class);
        $mapper->store($identity);

        $this->logCookieError($identity, 'compromised cookie');

        throw new CompromisedCookie;
    }


    public function discardIdentities(Entity\IdentityCollection $list)
    {
        foreach ($list as $identity) {
            $identity->setStatus(Entity\Identity::STATUS_DISCARDED);
        }

        $mapper = $this->mapperFactory->create(Mapper\IdentityCollection::class);
        $mapper->store($list);
    }


    /**
     * @param string $oldPassword
     * @param string $newPassword
     */
    public function changePassword(Entity\EmailIdentity $identity, $oldPassword, $newPassword)
    {
        $mapper = $this->mapperFactory->create(Mapper\EmailIdentity::class);

        if ($identity->matchPassword($oldPassword) === false) {
            $this->logWrongPasswordWarning($identity, [
                'account' => $identity->getAccountId(),
                'old-key' => md5($oldPassword),
                'new-key' => md5($newPassword),
            ]);

            throw new PasswordNotMatch;
        }

        $identity->setPassword($newPassword);
        $mapper->store($identity);

        $this->logger->info('password changed', [
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);
    }


    /**
     * @param string $message
     */
    private function logCookieError(Entity\CookieIdentity $identity, $message)
    {
        $this->logger->error($message, [
            'input' => [
                'account' => $identity->getAccountId(),
                'series' => $identity->getSeries(),
                'key' => $identity->getKey(),
            ],
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);
    }


    /**
     * @param array $input
     */
    private function logWrongPasswordWarning(Entity\EmailIdentity $identity, $input)
    {
        $this->logger->warning('wrong password', [
            'input' => $input,
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);
    }
}
