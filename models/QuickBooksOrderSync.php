<?php

declare(strict_types=1);

namespace Osmium\Services\QuickBooks\Models;

/**
 * QuickBooks's side of the admin order page: the sidebar panel (order.panels hook)
 * and the "Push to QuickBooks" action (order.action hook). Pushing is manual and
 * creates a real posted invoice, so it is guarded by the
 * allowedCompanyName lock.
 */
class QuickBooksOrderSync
{
    private const ACTION = 'push_to_quickbooks';
    private const PUSHABLE_STATUSES = ['paid', 'dispatched', 'refunded'];

    /**
     * order.panels handler.
     *
     * @param array{order: array, osmium: object} $payload
     */
    public static function orderPanel(array $payload): string
    {
        $order = $payload['order'];
        $invoice = self::findInvoice($payload['osmium']->dataSource, (int) $order['id']);

        $quickbooks = [
            'synced' => $invoice !== null,
            'syncedAt' => $invoice['synced_at'] ?? null,
            'invoiceNumber' => $invoice['invoice_number'] ?? null,
            'canPush' => $invoice === null && \in_array($order['status'], self::PUSHABLE_STATUSES, true),
        ];

        $cspNonce = \Osmium\Core\OsmiumSecurity::requestNonce(); // The view's inline script is blocked without it

        \ob_start();
        require __DIR__ . '/../views/quickbooks/order-panel.phtml';

        return (string) \ob_get_clean();
    }

    /**
     * order.action handler. Returns null for actions that aren't ours.
     *
     * @param array{action: string, orderId: int, input: array, osmium: object, admin?: object} $payload
     * @throws \RuntimeException when the push is refused or fails
     */
    public static function orderAction(array $payload): ?array
    {
        $notOurs = $payload['action'] !== self::ACTION;
        if ($notOurs) return null;

        $osmium = $payload['osmium'];
        $db = $osmium->dataSource;
        $orderId = $payload['orderId'];

        $order = self::loadOrder($db, $orderId);
        $orderMissing = $order === null;
        if ($orderMissing) throw new \RuntimeException('Order not found.');

        $alreadySynced = self::findInvoice($db, $orderId) !== null;
        if ($alreadySynced) throw new \RuntimeException('This order has already been pushed to QuickBooks.');

        $notPushable = !\in_array($order['status'], self::PUSHABLE_STATUSES, true);
        if ($notPushable) throw new \RuntimeException('Only a paid order can be pushed to QuickBooks.');

        $quickbooks = QuickBooksConfig::buildService($db);
        self::assertCompanyAllowed($quickbooks);

        $config = QuickBooksConfig::get();
        $salesItemId = (string) $config->salesItemId;
        $taxCodeId = (string) $config->taxCodeId;

        $customerId = $quickbooks->findOrCreateCustomer(name: $order['customer_name'], email: $order['customer_email']);

        $lineItems = \array_map(static fn(array $item): array => [
            'description' => $item['product_title'],
            'quantity' => (float) $item['quantity'],
            'unitAmount' => (float) $item['unit_price_exc_tax'],
            'itemId' => $salesItemId,
            'taxCodeId' => $taxCodeId,
        ], self::loadItems($db, $orderId));

        $hasShipping = (float) $order['shipping_amount'] > 0;
        if ($hasShipping) {
            $lineItems[] = [
                'description' => 'Delivery',
                'quantity' => 1,
                'unitAmount' => (float) $order['shipping_amount'],
                'itemId' => $salesItemId,
                'taxCodeId' => $taxCodeId,
            ];
        }

        $invoiceDate = \substr((string) ($order['paid_at'] ?? $order['created_at']), 0, 10);

        $invoice = $quickbooks->createInvoice(
            customerId: $customerId,
            lineItems: $lineItems,
            reference: $order['order_ref'],
            invoiceDate: $invoiceDate,
            dueDate: $invoiceDate,
        );

        self::recordInvoice($db, $orderId, $invoice);

        $admin = $payload['admin'] ?? null;
        if ($admin !== null) {
            $admin->model->changelog->log(
                description: "Pushed order {$order['order_ref']} to QuickBooks as invoice {$invoice['invoiceNumber']}",
                recordType: 'order',
                recordId: $orderId,
            );
        }

        return [
            'success' => true,
            'action' => self::ACTION,
            'id' => $orderId,
            'quickbooks_invoice_id' => $invoice['invoiceId'],
            'quickbooks_invoice_number' => $invoice['invoiceNumber'],
        ];
    }

    /**
     * Hard safety gate: a push creates a real invoice in whatever QuickBooks
     * company is connected, so it is refused unless the connected
     * company's name matches the one deliberately confirmed in settings.
     */
    private static function assertCompanyAllowed(QuickBooksService $quickbooks): void
    {
        $allowedName = \trim((string) QuickBooksConfig::get()->allowedCompanyName);
        $connectedName = $quickbooks->getConnection()['company_name'] ?? null;

        $noAllowedName = $allowedName === '';
        if ($noAllowedName) {
            throw new \RuntimeException(
                'QuickBooks pushing is locked: no allowed company is set. Confirm which QuickBooks company is connected (currently "'
                . ($connectedName ?? 'none') . '") in the QuickBooks settings before pushing.'
            );
        }

        $mismatch = $connectedName !== $allowedName;
        if ($mismatch) {
            throw new \RuntimeException(
                "QuickBooks pushing is locked to \"{$allowedName}\", but the connected company is \""
                . ($connectedName ?? 'none') . '". Refusing to push.'
            );
        }
    }

    private static function findInvoice(\Osmium\Core\Library\OsmiumPDO $db, int $orderId): ?array
    {
        $db->query("SELECT invoice_id, invoice_number, synced_at FROM {$db->tablePrefix()}quickbooks_invoices WHERE order_id = :id LIMIT 1");
        $db->bind(param: ':id', value: $orderId);

        return $db->single() ?: null;
    }

    private static function recordInvoice(\Osmium\Core\Library\OsmiumPDO $db, int $orderId, array $invoice): void
    {
        $db->query(
            "INSERT INTO {$db->tablePrefix()}quickbooks_invoices (order_id, invoice_id, invoice_number) "
            . 'VALUES (:order_id, :invoice_id, :invoice_number)'
        );
        $db->bind(param: ':order_id', value: $orderId);
        $db->bind(param: ':invoice_id', value: $invoice['invoiceId']);
        $db->bind(param: ':invoice_number', value: $invoice['invoiceNumber']);
        $db->execute();
    }

    private static function loadOrder(\Osmium\Core\Library\OsmiumPDO $db, int $orderId): ?array
    {
        $db->query("SELECT * FROM {$db->tablePrefix()}shop_orders WHERE id = :id LIMIT 1");
        $db->bind(param: ':id', value: $orderId);

        return $db->single() ?: null;
    }

    private static function loadItems(\Osmium\Core\Library\OsmiumPDO $db, int $orderId): array
    {
        $db->query("SELECT * FROM {$db->tablePrefix()}shop_order_items WHERE order_id = :id ORDER BY id ASC");
        $db->bind(param: ':id', value: $orderId);

        return $db->resultset();
    }
}
