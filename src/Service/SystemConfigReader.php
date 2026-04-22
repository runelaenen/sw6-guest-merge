<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class SystemConfigReader
{
    private const PREFIX = 'LaenenGuestMerge.config.';

    public function __construct(private readonly SystemConfigService $config) {}

    public function tokenLifetimeHours(): int
    {
        $val = (int)$this->config->get(self::PREFIX . 'tokenLifetimeHours');
        return $val > 0 ? $val : 24;
    }

    public function restrictToSameSalesChannel(): bool
    {
        return (bool)$this->config->get(self::PREFIX . 'restrictToSameSalesChannel');
    }

    public function sendCompletionEmail(): bool
    {
        $val = $this->config->get(self::PREFIX . 'sendCompletionEmail');
        return $val === null ? true : (bool)$val;
    }

    public function allowSelfServiceInitiation(): bool
    {
        $val = $this->config->get(self::PREFIX . 'allowSelfServiceInitiation');
        return $val === null ? true : (bool)$val;
    }

    public function allowDirectMergeForTrustedCsr(): bool
    {
        return (bool)$this->config->get(self::PREFIX . 'allowDirectMergeForTrustedCsr');
    }

    public function showRegistrationHint(): bool
    {
        $val = $this->config->get(self::PREFIX . 'showRegistrationHint');
        return $val === null ? true : (bool)$val;
    }
}
