<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Exception;

use Symfony\Component\HttpFoundation\Response;

class RequestExpiredException extends MergeException
{
    public function __construct()
    {
        parent::__construct('This merge request has expired. Please initiate a new one.');
    }

    public function getErrorCode(): string
    {
        return 'LAENEN_GUEST_MERGE__REQUEST_EXPIRED';
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_GONE;
    }
}
