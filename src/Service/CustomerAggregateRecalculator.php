<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * Recomputes order_count, order_total_amount, last_order_date on the customer row.
 *
 * Mirrors the logic Shopware uses when persisting orders so the auth customer's
 * aggregate fields stay consistent with the merged set of orders.
 *
 * Cancelled orders are excluded from order_count/order_total_amount, matching
 * Shopware core behaviour.
 */
class CustomerAggregateRecalculator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $customerRepository,
    ) {}

    public function recompute(string $customerHexId, Context $context): void
    {
        $aggregates = $this->computeAggregates($customerHexId);

        // orderCount, orderTotalAmount, lastOrderDate are write-protected (system scope required).
        // Elevate scope only for this write; all other context properties are preserved.
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($customerHexId, $aggregates): void {
            $this->customerRepository->update([[
                'id' => $customerHexId,
                'orderCount' => $aggregates['orderCount'],
                'orderTotalAmount' => $aggregates['orderTotalAmount'],
                'lastOrderDate' => $aggregates['lastOrderDate'],
            ]], $context);
        });
    }

    /**
     * @return array{orderCount:int, orderTotalAmount:float, lastOrderDate:?\DateTimeImmutable}
     */
    private function computeAggregates(string $customerHexId): array
    {
        $sql = "SELECT
                    COUNT(DISTINCT o.id)             AS orderCount,
                    COALESCE(SUM(o.amount_total), 0) AS totalAmount,
                    MAX(o.order_date_time)           AS lastOrderDate
                FROM `order` o
                INNER JOIN order_customer oc
                    ON oc.order_id = o.id
                   AND oc.order_version_id = o.version_id
                INNER JOIN state_machine_state sms
                    ON sms.id = o.state_id
                WHERE oc.customer_id = :cid
                  AND o.version_id = :liveVersion
                  AND sms.technical_name <> 'cancelled'";

        $row = $this->connection->fetchAssociative($sql, [
            'cid' => Uuid::fromHexToBytes($customerHexId),
            'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ]) ?: ['orderCount' => 0, 'totalAmount' => 0, 'lastOrderDate' => null];

        return [
            'orderCount' => (int)$row['orderCount'],
            'orderTotalAmount' => (float)$row['totalAmount'],
            'lastOrderDate' => $row['lastOrderDate'] !== null
                ? new \DateTimeImmutable($row['lastOrderDate'])
                : null,
        ];
    }
}
