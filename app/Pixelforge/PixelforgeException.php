<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Any failed call to Pixelforge: an error response, or no response at all.
 */
final class PixelforgeException extends RuntimeException
{
    /**
     * @param  int  $status  The HTTP status, or 0 when Pixelforge couldn't be reached.
     * @param  string|null  $errorCode  Pixelforge's own error code, e.g. "overloaded".
     * @param  int|null  $retryAfter  Seconds Pixelforge asked us to wait before retrying.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            message: $response->json('error.message') ?? "Pixelforge responded with HTTP {$response->status()}.",
            status: $response->status(),
            errorCode: $response->json('error.code'),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public static function unreachable(ConnectionException $exception): self
    {
        return new self(
            message: "Pixelforge couldn't be reached: {$exception->getMessage()}",
            status: 0,
            previous: $exception,
        );
    }

    /**
     * Pixelforge is telling us to wait our turn. Nothing is wrong with the
     * request itself, so it should simply be sent again later.
     */
    public function isCapacityLimit(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true);
    }

    /**
     * Pixelforge had a temporary problem (overloaded, an outage, a timeout),
     * so the same request is worth a few more tries.
     */
    public function isTransient(): bool
    {
        return $this->status === 0 || $this->status >= 500;
    }
}
