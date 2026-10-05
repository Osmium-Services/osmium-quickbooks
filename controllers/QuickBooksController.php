<?php

declare(strict_types=1);

namespace Osmium\Services\QuickBooks\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\QuickBooks\Models\QuickBooksConfig;
use Osmium\Services\QuickBooks\Models\QuickBooksService;

/**
 * QuickBooks settings controller - credentials, OAuth2 connection and status.
 *
 * Routes:
 *   - index()    → /admin/settings/quickbooks/           (status + settings form)
 *   - connect()  → /admin/settings/quickbooks/connect/   (redirects to QuickBooks)
 *   - callback() → /admin/settings/quickbooks/callback/  (QuickBooks redirects back here)
 *   - action()   → /admin/settings/quickbooks/action/    (disconnect, test_connection)
 */
class QuickBooksController extends AdminController
{
    private const STATE_SESSION_KEY = 'quickbooks_oauth_state';
    private const FLASH_KEY = 'quickbooks_flash';

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $error = $this->osmium->getStashedParam(key: 'error', default: null);
        $justConnected = $this->osmium->getStashedParam(key: 'connected', default: null);
        $this->osmium->clearStashedQuerystring();

        $config = (array) QuickBooksConfig::get();
        $connection = $this->quickbooks()->getConnection();

        $this->data['admin']['quickbooks'] = [
            'clientId' => $config['clientId'],
            'hasSecret' => $config['clientSecret'] !== '',
            'redirectUri' => $config['redirectUri'] ?: $this->suggestedRedirectUri(),
            'salesItemId' => $config['salesItemId'],
            'taxCodeId' => $config['taxCodeId'],
            'sandbox' => (bool) $config['sandbox'],
            'allowedCompanyName' => $config['allowedCompanyName'],
            'clientConfigured' => $config['clientId'] !== '' && $config['clientSecret'] !== '',
            'connected' => $connection !== null,
            'companyName' => $connection['company_name'] ?? null,
            'connectedAt' => $connection['connected_at'] ?? null,
            'error' => $error,
            'justConnected' => $justConnected !== null,
        ];
        $this->data['admin']['flash'] = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);

        $this->setView('quickbooks/index.phtml');
    }

    /**
     * Start the OAuth2 flow - redirects the admin to QuickBooks's consent screen.
     */
    public function connect(): void
    {
        $state = \bin2hex(\random_bytes(16));
        $_SESSION[self::STATE_SESSION_KEY] = $state;

        $this->osmium->header->redirect(targetURL: $this->quickbooks()->getAuthorizationUrl(state: $state));
    }

    /**
     * OAuth2 redirect target - exchanges the code QuickBooks sent back for tokens.
     */
    public function callback(): void
    {
        // QuickBooks's ?code=&state=&realmId= never reach $_GET here - the framework's global
        // stashQuerystring() already stripped them into $_SESSION['qs'] and
        // 302'd to this same clean URL before this method ever runs.
        $returnedState = (string) $this->osmium->getStashedParam(key: 'state', default: '');
        $error = (string) $this->osmium->getStashedParam(key: 'error', default: '');
        $code = (string) $this->osmium->getStashedParam(key: 'code', default: '');
        $realmId = (string) $this->osmium->getStashedParam(key: 'realmId', default: '');
        $this->osmium->clearStashedQuerystring();

        $expectedState = $_SESSION[self::STATE_SESSION_KEY] ?? '';
        unset($_SESSION[self::STATE_SESSION_KEY]);

        $stateInvalid = empty($expectedState) || !\hash_equals($expectedState, $returnedState);
        if ($stateInvalid) $this->redirect('settings/quickbooks/?error=' . \rawurlencode('Invalid OAuth state'));

        if ($error) $this->redirect('settings/quickbooks/?error=' . \rawurlencode($error));

        $codeMissing = empty($code);
        if ($codeMissing) $this->redirect('settings/quickbooks/?error=' . \rawurlencode('No authorization code returned'));

        $realmMissing = empty($realmId);
        if ($realmMissing) $this->redirect('settings/quickbooks/?error=' . \rawurlencode('No company ID returned'));

        try {
            $this->quickbooks()->completeAuthorization(code: $code, realmId: $realmId);
        } catch (\Exception $e) {
            $this->redirect('settings/quickbooks/?error=' . \rawurlencode($e->getMessage()));
        }

        $this->redirect('settings/quickbooks/?connected=1');
    }

    /**
     * Action endpoint - disconnect and test_connection.
     */
    public function action()
    {
        \header('Content-Type: application/json');

        $isPostMethod = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPostMethod) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = $input['action'] ?? '';

        $invalidToken = !$this->admin->auth->validateCsrfJson($input);
        if ($invalidToken) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }

        match ($action) {
            'test_connection' => $this->testConnection(),
            'disconnect' => $this->disconnect(),
            default => $this->admin->jsonError('Unknown action'),
        };
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->flashAndRedirect('danger', 'Invalid form submission. Please try again.');

        $current = QuickBooksConfig::get();

        $postedSecret = \trim($_POST['client_secret'] ?? '');
        $clientSecret = $postedSecret === '' ? (string) $current->clientSecret : $postedSecret; // Blank keeps the stored secret

        QuickBooksConfig::save([
            'clientId' => \trim($_POST['client_id'] ?? ''),
            'clientSecret' => $clientSecret,
            'redirectUri' => \trim($_POST['redirect_uri'] ?? ''),
            'salesItemId' => \trim($_POST['sales_item_id'] ?? '') ?: '1',
            'taxCodeId' => \trim($_POST['tax_code_id'] ?? ''),
            'sandbox' => !empty($_POST['sandbox']),
            'allowedCompanyName' => \trim($_POST['allowed_company_name'] ?? ''),
        ]);

        $this->admin->model->changelog->log(
            description: 'Updated QuickBooks settings',
            recordType: 'settings',
        );

        $this->flashAndRedirect('success', 'Settings saved successfully!');
    }

    private function testConnection(): void
    {
        $result = $this->quickbooks()->testConnection();

        if ($result['success']) {
            $this->admin->jsonSuccess($result);
        }

        $this->admin->jsonError($result['message']);
    }

    private function disconnect(): void
    {
        $this->quickbooks()->disconnect();

        $this->admin->model->changelog->log(
            description: 'Disconnected QuickBooks',
            recordType: 'quickbooks_connection',
            recordId: 0,
        );

        $this->admin->jsonSuccess(['success' => true]);
    }

    private function quickbooks(): QuickBooksService
    {
        return QuickBooksConfig::buildService($this->osmium->dataSource);
    }

    private function suggestedRedirectUri(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return "{$scheme}://{$_SERVER['HTTP_HOST']}{$this->data['admin']['basePath']}settings/quickbooks/callback/";
    }

    private function flashAndRedirect(string $type, string $text): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => $type, 'text' => $text];
        $this->redirect('settings/quickbooks/');
    }
}
