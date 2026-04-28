<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Exception;

use Symfony\Component\HttpFoundation\Response;

class SelfServiceDisabledException extends MergeException
{
    public function __construct()
    {
        parent::__construct('Self-service merge initiation is disabled.');
    }

    public function getErrorCode(): string
    {
        return 'LAENEN_GUEST_MERGE__SELF_SERVICE_DISABLED';
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
