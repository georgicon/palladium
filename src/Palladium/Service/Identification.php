<?php

namespace Palladium\Service;

/**
 * Retrieval and handling of identities for registered users
 */

use Palladium\Mapper as Mapper;
use Palladium\Entity as Entity;
use Palladium\Exception\PasswordMismatch;
use Palladium\Exception\KeyMismatch;
use Palladium\Exception\CompromisedCookie;
use Palladium\Exception\IdentityExpired;
use Palladium\Contract\CanCreateMapper;
use Psr\Log\LoggerInterface;

class Identification
{

    const DEFAULT_COOKIE_LIFESPAN = 14400; // 4 hours

    private $mapperFactory;
    private $logger;

    private $cookieLifespan;

    /**
     * @param Palladium\Contract\CanCreateMapper $mapperFactory Factory for creating persistence layer structures
     * @param Psr\Log\LoggerInterface $logger PSR-3 compatible logger
     * @param int $cookieLifespan Lifespan of the authentication cookie in seconds
     */
    public function __construct(CanCreateMapper $mapperFactory, LoggerInterface $logger, $cookieLifespan = self::DEFAULT_COOKIE_LIFESPAN)
    {
        $this->mapperFactory = $mapperFactory;
        $this->logger = $logger;
        $this->cookieLifespan = $cookieLifespan;
    }


    /**
     * @param string $password
     *
     * @return Palladium\Entity\CookieIdentity
     */
    public function loginWithPassword(Entity\EmailIdentity $identity, $password)
    {
        if ($identity->matchPassword($password) === false) {
            $this->logWrongPasswordNotice($identity, [
                'email' => $identity->getEmailAddress(),
                'key' => md5($password),
            ]);

            throw new PasswordMismatch;
        }

        $this->registerUsageOfIdentity($identity);
        $cookie = $this->createCookieIdentity($identity);

        $this->logger->info('login successful', [
            'input' => [
                'email' => $identity->getEmailAddress(),
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


    private function createCookieIdentity(Entity\Identity $identity)
    {
        $cookie = new Entity\CookieIdentity;
        $mapper = $this->mapperFactory->create(Mapper\CookieIdentity::class);

        $cookie->setAccountId($identity->getAccountId());
        $cookie->generateNewSeries();

        $cookie->generateNewKey();
        $cookie->setStatus(Entity\Identity::STATUS_ACTIVE);
        $cookie->setExpiresOn(time() + $this->cookieLifespan);


        $parentId = $identity->getParentId();

        if (null === $parentId) {
            $parentId = $identity->getId();
        }

        $cookie->setParentId($parentId);

        $mapper->store($cookie);

        return $cookie;
    }


    /**
     * @param string @key
     *
     * @throws \Palladium\Exception\CompromisedCookie if key does not match
     * @throws \Palladium\Exception\IdentityExpired if cookie is too old
     *
     * @return Palladium\Entity\CookieIdentity
     */
    public function loginWithCookie(Entity\CookieIdentity $identity, $key)
    {
        $this->checkCookieExpireTime($identity);
        $this->checkCookieKey($identity, $key);

        $identity->generateNewKey();
        $identity->setLastUsed(time());
        $identity->setExpiresOn(time() + $this->cookieLifespan);

        $mapper = $this->mapperFactory->create(Mapper\CookieIdentity::class);
        $mapper->store($identity);

        $this->logExpectedBehaviour($identity, 'cookie updated');

        return $identity;
    }


    /**
     * @param string $key
     */
    public function logout(Entity\CookieIdentity $identity, $key)
    {
        $this->checkCookieExpireTime($identity);
        $this->checkCookieKey($identity, $key);

        $identity->setStatus(Entity\Identity::STATUS_DISCARDED);

        $mapper = $this->mapperFactory->create(Mapper\CookieIdentity::class);
        $mapper->store($identity);

        $this->logExpectedBehaviour($identity, 'logout successful');
    }


    private function checkCookieExpireTime(Entity\CookieIdentity $identity)
    {
        if ($identity->getExpiresOn() < time()) {
            $identity->setStatus(Entity\Identity::STATUS_EXPIRED);

            $this->logger->info('cookie expired', $this->assembleCookieLogDetails($identity));

            $mapper = $this->mapperFactory->create(Mapper\CookieIdentity::class);
            $mapper->store($identity);

            throw new IdentityExpired;
        }
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

        $this->logger->warning('compromised cookie', $this->assembleCookieLogDetails($identity));

        throw new CompromisedCookie;
    }


    /**
     * @return array
     */
    private function assembleCookieLogDetails(Entity\CookieIdentity $identity)
    {
        return [
            'input' => [
                'account' => $identity->getAccountId(),
                'series' => $identity->getSeries(),
                'key' => $identity->getKey(),
            ],
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ];
    }


    public function discardIdentityCollection(Entity\IdentityCollection $list)
    {
        foreach ($list as $identity) {
            $identity->setStatus(Entity\Identity::STATUS_DISCARDED);
        }

        $mapper = $this->mapperFactory->create(Mapper\IdentityCollection::class);
        $mapper->store($list);
    }


    public function blockIdentity(Entity\Identity $identity)
    {
        $identity->setStatus(Entity\Identity::STATUS_BLOCKED);

        $mapper = $this->mapperFactory->create(Mapper\Identity::class);
        $mapper->store($identity);
    }


    /**
     * @codeCoverageIgnore
     */
    public function deleteIdentity(Entity\Identity $identity)
    {
        $mapper = $this->mapperFactory->create(Mapper\Identity::class);
        $mapper->remove($identity);
    }


    /**
     * @param string $oldPassword
     * @param string $newPassword
     */
    public function changePassword(Entity\EmailIdentity $identity, $oldPassword, $newPassword)
    {
        $mapper = $this->mapperFactory->create(Mapper\EmailIdentity::class);

        if ($identity->matchPassword($oldPassword) === false) {
            $this->logWrongPasswordNotice($identity, [
                'account' => $identity->getAccountId(),
                'old-key' => md5($oldPassword),
                'new-key' => md5($newPassword),
            ]);

            throw new PasswordMismatch;
        }

        $identity->setPassword($newPassword);
        $mapper->store($identity);

        $this->logExpectedBehaviour($identity, 'password changed');
    }


    /**
     * @param array $input
     */
    private function logWrongPasswordNotice(Entity\EmailIdentity $identity, $input)
    {
        $this->logger->notice('wrong password', [
            'input' => $input,
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);
    }


    private function logExpectedBehaviour(Entity\Identity $identity, $message)
    {
        $this->logger->info($message, [
            'user' => [
                'account' => $identity->getAccountId(),
                'identity' => $identity->getId(),
            ],
        ]);
    }


    public function useOneTimeIdentity(Entity\OneTimeIdentity $identity, $key)
    {
        if ($identity->matchKey($key) === false) {
            $this->logger->notice('wrong key', [
                'input' => [
                    'key' => md5($key),
                ],
                'user' => [
                    'account' => $identity->getAccountId(),
                    'identity' => $identity->getId(),
                ],
            ]);

            throw new KeyMismatch;
        }

        $identity->setStatus(Entity\Identity::STATUS_DISCARDED);

        $mapper = $this->mapperFactory->create(Mapper\OneTimeIdentity::class);
        $mapper->store($identity);

        $this->logExpectedBehaviour($identity, 'one-time identity used');

        return $this->createCookieIdentity($identity);
    }
}
