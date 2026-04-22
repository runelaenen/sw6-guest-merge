<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Exception;

use Symfony\Component\HttpFoundation\Response;

class NoCandidatesException extends MergeException
{
    public function __construct(string $email)
    {
        parent::__construct(
            'No guest orders found for email "{{ email }}".',
            ['email' => $email]
        );
    }

    public function getErrorCode(): string
    {
        return 'LAENEN_GUEST_MERGE__NO_CANDIDATES';
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
