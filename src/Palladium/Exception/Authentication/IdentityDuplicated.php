<?php

namespace Exception\Authentication;

use Palladium\Component\AppException as Exception;


class IdentityDuplicated extends Exception
{
    protected $code = 0;
    protected $message = 'message.error.identoty-duplicated';
}
