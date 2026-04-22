<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730000000CreateMergeRequestTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000000;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `laenen_guest_merge_request` (
    `id`                   BINARY(16)   NOT NULL,
    `customer_id`          BINARY(16)   NOT NULL,
    `email`                VARCHAR(255) NOT NULL,
    `token_hash`           CHAR(64)     NOT NULL,
    `short_code_hash`      CHAR(64)     NOT NULL,
    `status`               VARCHAR(20)  NOT NULL,
    `verification_method`  VARCHAR(20)  NULL,
    `candidate_count`      INT UNSIGNED NOT NULL DEFAULT 0,
    `order_count_snapshot` INT UNSIGNED NOT NULL DEFAULT 0,
    `moved_order_count`    INT UNSIGNED NULL,
    `deleted_guest_ids`    JSON         NULL,
    `expires_at`           DATETIME(3)  NOT NULL,
    `confirmed_at`         DATETIME(3)  NULL,
    `completed_at`         DATETIME(3)  NULL,
    `initiated_by_user_id` BINARY(16)   NULL,
    `error_message`        TEXT         NULL,
    `created_at`           DATETIME(3)  NOT NULL,
    `updated_at`           DATETIME(3)  NULL,
    PRIMARY KEY (`id`),
    KEY `idx.laenen_gmr.token_hash` (`token_hash`),
    KEY `idx.laenen_gmr.short_code_hash` (`short_code_hash`),
    KEY `idx.laenen_gmr.customer_status` (`customer_id`, `status`),
    CONSTRAINT `fk.laenen_gmr.customer_id`
        FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
