<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class LdapTestResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?int $latencyMs = null,
        public ?int $entriesFound = null,
    ) {
    }
}
