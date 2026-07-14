<?php

namespace App\Exceptions;

use RuntimeException;

class StaleRevisionException extends RuntimeException
{
    public function __construct(
        public readonly int $currentVersionNo,
        public readonly int $baseVersionNo,
    ) {
        parent::__construct(
            "program was revised since you loaded this page (current v{$currentVersionNo}, you loaded v{$baseVersionNo}) — reload"
        );
    }
}
