<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Exception;

use Symfony\Component\HttpFoundation\Response;

class InvalidTokenException extends MergeException
{
    public function __construct(string $reason = 'Invalid or unknown token.')
    {
        parent::__construct($reason);
    }

    public function getErrorCode(): string
    {
        return 'LAENEN_GUEST_MERGE__INVALID_TOKEN';
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_GONE;
    }
}
