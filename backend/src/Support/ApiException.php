<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Domain/HTTP exception carrying the standard error envelope fields (SPEC §7.3).
 */
class ApiException extends RuntimeException
{
    /** @var array<string,mixed>|null */
    private ?array $details;

    private int $status;

    private string $errorCode;

    /**
     * @param array<string,mixed>|null $details
     */
    public function __construct(int $status, string $errorCode, string $message, ?array $details = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed>|null */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    public static function notFound(string $message = 'Risorsa non trovata.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function forbidden(string $message = 'Non hai i permessi per questa operazione.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function unauthenticated(string $code = 'unauthenticated', string $message = 'Autenticazione richiesta.'): self
    {
        return new self(401, $code, $message);
    }

    /**
     * @param array<string,mixed>|null $details
     */
    public static function validation(array $details, string $message = 'I dati inviati non sono validi.'): self
    {
        return new self(422, 'validation_failed', $message, $details);
    }

    /**
     * @param array<string,mixed>|null $details
     */
    public static function conflict(string $code, string $message, ?array $details = null): self
    {
        return new self(409, $code, $message, $details);
    }
}
