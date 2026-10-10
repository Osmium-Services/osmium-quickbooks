<?php

declare(strict_types=1);

namespace Tests\Services\QuickBooks;

use Osmium\Services\QuickBooks\Models\QuickBooksOrderSync;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/QuickBooksOrderSync.php';

/**
 * The order page's QuickBooks panel carries an inline script for its push button.
 * The browser runs it only if the tag has the request's CSP nonce.
 */
class OrderPanelTest extends DatabaseTestCase
{
    public function test_the_push_button_script_has_a_real_csp_nonce(): void
    {
        $order = ['id' => 987654, 'status' => 'paid'];

        $panel = QuickBooksOrderSync::orderPanel(['order' => $order, 'osmium' => (object) ['dataSource' => $this->db]]);

        $this->assertStringContainsString('<script', $panel);
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+"/', $panel, 'An empty nonce makes the browser block the script');
    }
}
