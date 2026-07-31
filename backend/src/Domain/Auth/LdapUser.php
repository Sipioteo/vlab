<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Immutable value object describing a directory user (SPEC §4.2).
 */
final class LdapUser
{
    public function __construct(
        public readonly string $uid,
        public readonly ?string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $displayName,
        /** @var string[] full DNs or CNs of groups */
        public readonly array $groups = [],
        /** @var array<string,mixed> raw attributes, for debugging */
        public readonly array $raw = [],
    ) {
    }
}
