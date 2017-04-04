<?php

namespace Palladium\Entity\Authentication;

use RuntimeException;
use Palladium\Exception\Authentication\InvalidPassword;
use Palladium\Exception\Authentication\InvalidEmail;


class PasswordIdentity extends Identity
{

    const HASH_ALGO = PASSWORD_BCRYPT;
    const HASH_COST = 12;

    const MIN_LENGTH = 6;
    const MAX_LENGTH = 128;


    private $identifier;
    private $key;
    private $hash;

    protected $type = Identity::TYPE_PASSWORD;


    public function setIdentifier($identifier)
    {
        $this->identifier = (string) $identifier;
    }


    /**
     * @codeCoverageIgnore
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }


    public function getFingerprint()
    {
        return hash('sha384', $this->identifier);
    }


    public function setKey($key)
    {
        $this->key = (string) $key;
        $this->hash = null;
    }


    public function getHash()
    {
        if (null !== $this->key && null === $this->hash) {
            $this->hash = $this->createHash($this->key);
        }

        return $this->hash;
    }


    private function createHash($key)
    {
        $hash = password_hash($key, self::HASH_ALGO, ['cost' => self::HASH_COST]);

        return $hash;
    }


    public function setHash($hash)
    {
        if (null === $hash) {
            $this->hash = null;
            return;
        }
        $this->hash = (string) $hash;
    }


    public function matchKey($key)
    {
        return password_verify($key, $this->hash);
    }


    public function isOldHash()
    {
        return password_needs_rehash($this->hash, self::HASH_ALGO, ['cost' => self::HASH_COST]);
    }


    public function validate()
    {
        if (false === filter_var($this->identifier, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidEmail;
        }

        if (strlen($this->key) < self::MIN_LENGTH || strlen($this->key) > self::MAX_LENGTH) {
            throw new InvalidPassword;
        }
    }
}
