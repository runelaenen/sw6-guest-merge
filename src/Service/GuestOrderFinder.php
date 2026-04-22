<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Service;

use Doctrine\DBAL\Connection;
use Laenen\GuestMerge\Dto\GuestCandidatesDto;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

class GuestOrderFinder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigReader $config,
    ) {}

    /**
     * Find all guest customer rows with the same (case-insensitive) email as the
     * authenticated customer, plus aggregated metrics about their orders.
     */
    public function findCandidatesFor(
        string $authCustomerHexId,
        ?string $salesChannelHexId = null
    ): GuestCandidatesDto {
        $email = $this->fetchCustomerEmail($authCustomerHexId);

        if ($email === null) {
            return $this->empty('');
        }

        $guests = $this->findGuestRowsByEmail($email, $authCustomerHexId, $salesChannelHexId);

        if ($guests === []) {
            return $this->empty($email);
        }

        $guestIdsBin = array_map(static fn(array $g) => Uuid::fromHexToBytes($g['id']), $guests);
        $guestIdsHex = array_map(static fn(array $g) => $g['id'], $guests);

        $metrics = $this->fetchOrderMetrics($guestIdsBin);
        $bySalesChannel = $this->fetchPerSalesChannelMetrics($guestIdsBin);

        return new GuestCandidatesDto(
            email: $email,
            guestCustomerIds: $guestIdsHex,
            guestCustomers: $guests,
            orderCount: (int)$metrics['orderCount'],
            totalAmount: (float)$metrics['totalAmount'],
            oldestOrderDate: $metrics['oldestOrderDate'] ?: null,
            newestOrderDate: $metrics['newestOrderDate'] ?: null,
            bySalesChannel: $bySalesChannel,
        );
    }

    /**
     * Used inside the merge transaction. Re-discovers guest IDs by the
     * authenticated customer's CURRENT email (not the snapshot on the request).
     *
     * @return string[] hex UUIDs of guest customers
     */
    public function findGuestCustomerIdsByEmail(
        string $email,
        string $authCustomerHexId,
        ?string $salesChannelHexId = null
    ): array {
        $rows = $this->findGuestRowsByEmail($email, $authCustomerHexId, $salesChannelHexId);
        return array_map(static fn(array $r) => $r['id'], $rows);
    }

    public function fetchCustomerEmail(string $customerHexId): ?string
    {
        $email = $this->connection->fetchOne(
            'SELECT email FROM customer WHERE id = :id LIMIT 1',
            ['id' => Uuid::fromHexToBytes($customerHexId)]
        );
        return $email === false ? null : (string)$email;
    }

    /**
     * @return array<int, array{id:string, salesChannelId:string, createdAt:string}>
     */
    private function findGuestRowsByEmail(
        string $email,
        string $excludeAuthCustomerHexId,
        ?string $salesChannelHexId
    ): array {
        $sql = "SELECT
                    LOWER(HEX(id))               AS id,
                    LOWER(HEX(sales_channel_id)) AS salesChannelId,
                    created_at                   AS createdAt
                FROM customer
                WHERE LOWER(email) = LOWER(:email)
                  AND guest = 1
                  AND id <> :exclude";

        $params = [
            'email' => $email,
            'exclude' => Uuid::fromHexToBytes($excludeAuthCustomerHexId),
        ];

        if ($salesChannelHexId !== null && $this->config->restrictToSameSalesChannel()) {
            $sql .= ' AND sales_channel_id = :sc';
            $params['sc'] = Uuid::fromHexToBytes($salesChannelHexId);
        }

        $sql .= ' ORDER BY created_at ASC';

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @param string[] $guestIdsBin
     * @return array{orderCount:int, totalAmount:float, oldestOrderDate:?string, newestOrderDate:?string}
     */
    private function fetchOrderMetrics(array $guestIdsBin): array
    {
        if ($guestIdsBin === []) {
            return ['orderCount' => 0, 'totalAmount' => 0.0, 'oldestOrderDate' => null, 'newestOrderDate' => null];
        }

        $sql = "SELECT
                    COUNT(DISTINCT o.id)        AS orderCount,
                    COALESCE(SUM(o.amount_total), 0) AS totalAmount,
                    MIN(o.order_date_time)      AS oldestOrderDate,
                    MAX(o.order_date_time)      AS newestOrderDate
                FROM `order` o
                INNER JOIN order_customer oc
                    ON oc.order_id = o.id
                   AND oc.order_version_id = o.version_id
                WHERE oc.customer_id IN (:ids)
                  AND o.version_id = :liveVersion";

        $row = $this->connection->fetchAssociative($sql, [
            'ids' => $guestIdsBin,
            'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ], [
            'ids' => Connection::PARAM_STR_ARRAY,
        ]);

        return $row ?: ['orderCount' => 0, 'totalAmount' => 0.0, 'oldestOrderDate' => null, 'newestOrderDate' => null];
    }

    /**
     * @param string[] $guestIdsBin
     * @return array<int, array{salesChannelId:string, salesChannelName:?string, orderCount:int, totalAmount:float}>
     */
    private function fetchPerSalesChannelMetrics(array $guestIdsBin): array
    {
        if ($guestIdsBin === []) {
            return [];
        }

        $sql = "SELECT
                    LOWER(HEX(o.sales_channel_id)) AS salesChannelId,
                    sct.name                       AS salesChannelName,
                    COUNT(DISTINCT o.id)           AS orderCount,
                    COALESCE(SUM(o.amount_total), 0) AS totalAmount
                FROM `order` o
                INNER JOIN order_customer oc
                    ON oc.order_id = o.id
                   AND oc.order_version_id = o.version_id
                LEFT JOIN sales_channel_translation sct
                    ON sct.sales_channel_id = o.sales_channel_id
                   AND sct.language_id = :defaultLang
                WHERE oc.customer_id IN (:ids)
                  AND o.version_id = :liveVersion
                GROUP BY o.sales_channel_id, sct.name";

        return $this->connection->fetchAllAssociative($sql, [
            'ids' => $guestIdsBin,
            'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'defaultLang' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
        ], [
            'ids' => Connection::PARAM_STR_ARRAY,
        ]);
    }

    private function empty(string $email): GuestCandidatesDto
    {
        return new GuestCandidatesDto(
            email: $email,
            guestCustomerIds: [],
            guestCustomers: [],
            orderCount: 0,
            totalAmount: 0.0,
            oldestOrderDate: null,
            newestOrderDate: null,
            bySalesChannel: [],
        );
    }
}
