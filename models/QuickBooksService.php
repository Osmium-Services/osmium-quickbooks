<?php

/**
 * QuickBooks Online Accounting API Service
 *
 * Handles OAuth2 connection (authorize, token exchange, refresh) and the
 * accounting calls needed to push a paid order to QuickBooks as a sales invoice.
 *
 * API Documentation: https://developer.intuit.com/app/developer/qbo/docs/api/accounting/most-commonly-used/invoice
 */

namespace Osmium\Services\QuickBooks\Models;

use Osmium\Core\Library\OsmiumPDO;

class QuickBooksService
{
    private const AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';
    private const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    private const API_HOST_LIVE = 'https://quickbooks.api.intuit.com';
    private const API_HOST_SANDBOX = 'https://sandbox-quickbooks.api.intuit.com';
    private const MINOR_VERSION = 75;
    private const SCOPES = 'com.intuit.quickbooks.accounting';
    private const TOKEN_REFRESH_MARGIN_SECONDS = 120; // Refresh a bit before actual expiry to avoid races

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private bool $sandbox;
    private OsmiumPDO $database;
    private string $tablePrefix;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        bool $sandbox,
        OsmiumPDO $database,
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->sandbox = $sandbox;
        $this->database = $database;
        $this->tablePrefix = $database->tablePrefix();
    }

    // ----------------------------------------
    // OAuth2 connection flow
    // ----------------------------------------

    /**
     * Build the URL to send the admin to in order to authorize this app.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $query = \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
        ]);

        return self::AUTHORIZE_URL . '?' . $query;
    }

    /**
     * Exchange an authorization code for tokens, fetch the connected company's
     * name, and persist the connection. Called from the OAuth callback.
     *
     * QuickBooks sends the company (realm) ID back on the redirect alongside
     * the code, rather than from a separate connections call.
     */
    public function completeAuthorization(string $code, string $realmId): array
    {
        $tokens = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        $companyName = $this->fetchCompanyName(accessToken: $tokens['access_token'], realmId: $realmId);

        $this->saveConnection(
            realmId: $realmId,
            companyName: $companyName,
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresIn: $tokens['expires_in'],
        );

        return ['realmId' => $realmId, 'companyName' => $companyName];
    }

    /**
     * The currently connected company, or null if never connected.
     */
    public function getConnection(): ?array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}quickbooks_connection ORDER BY id DESC LIMIT 1";
        $this->database->query($sql);

        return $this->database->single() ?: null;
    }

    /**
     * Remove the stored connection. The admin must re-authorize to reconnect.
     */
    public function disconnect(): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}quickbooks_connection";
        $this->database->query($sql);
        $this->database->execute();
    }

    /**
     * A valid access token for the connected company, refreshing it first if
     * it's expired or close to it. QuickBooks rotates the refresh token on
     * every refresh, so the new one must be saved or the connection is lost.
     *
     * @throws \Exception If QuickBooks has never been connected
     */
    private function ensureValidAccessToken(): array
    {
        $connection = $this->getConnection();
        $notConnected = $connection === null;
        if ($notConnected) throw new \Exception('QuickBooks is not connected');

        $expiresAt = \strtotime($connection['access_token_expires_at']);
        $expiringSoon = $expiresAt - \time() <= self::TOKEN_REFRESH_MARGIN_SECONDS;

        if (!$expiringSoon) {
            return ['access_token' => $connection['access_token'], 'realm_id' => $connection['realm_id']];
        }

        $tokens = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection['refresh_token'],
        ]);

        $this->saveConnection(
            realmId: $connection['realm_id'],
            companyName: $connection['company_name'],
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresIn: $tokens['expires_in'],
        );

        return ['access_token' => $tokens['access_token'], 'realm_id' => $connection['realm_id']];
    }

    /**
     * The connection is single-company, so refreshing just replaces the one row.
     */
    private function saveConnection(
        string $realmId,
        string $companyName,
        string $accessToken,
        string $refreshToken,
        int $expiresIn,
    ): void {
        $expiresAt = \date('Y-m-d H:i:s', \time() + $expiresIn);

        $this->disconnect();

        $sql = "INSERT INTO {$this->tablePrefix}quickbooks_connection "
            . "(realm_id, company_name, access_token, refresh_token, access_token_expires_at) "
            . "VALUES (:realm_id, :company_name, :access_token, :refresh_token, :expires_at)";

        $this->database->query($sql);
        $this->database->bind(param: ':realm_id', value: $realmId);
        $this->database->bind(param: ':company_name', value: $companyName);
        $this->database->bind(param: ':access_token', value: $accessToken);
        $this->database->bind(param: ':refresh_token', value: $refreshToken);
        $this->database->bind(param: ':expires_at', value: $expiresAt);
        $this->database->execute();
    }

    /**
     * Exchange a code or refresh token for a new access/refresh token pair.
     */
    private function requestToken(array $parameters): array
    {
        $ch = \curl_init(self::TOKEN_URL);
        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query($parameters),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . \base64_encode("{$this->clientId}:{$this->clientSecret}"),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = \curl_exec($ch);
        $curlError = \curl_error($ch);

        if ($curlError) throw new \Exception("QuickBooks token request curl error: {$curlError}");

        $decoded = \json_decode(json: $response, associative: true);

        $hasError = isset($decoded['error']);
        if ($hasError) {
            $description = $decoded['error_description'] ?? $decoded['error'];
            throw new \Exception("QuickBooks token request failed: {$description}");
        }

        return $decoded;
    }

    /**
     * The connected company's name, used for the "allowed company" safety lock.
     */
    private function fetchCompanyName(string $accessToken, string $realmId): string
    {
        $response = $this->request(
            method: 'GET',
            path: "companyinfo/{$realmId}",
            accessToken: $accessToken,
            realmId: $realmId,
        );

        return (string) ($response['CompanyInfo']['CompanyName'] ?? '');
    }

    // ----------------------------------------
    // Accounting API
    // ----------------------------------------

    /**
     * Find a customer by email, creating one if none exists.
     *
     * QuickBooks requires DisplayName to be unique across customers, vendors
     * and employees, so a name clash retries with the email appended.
     *
     * @return string The QuickBooks customer Id
     */
    public function findOrCreateCustomer(string $name, string $email): string
    {
        $existing = $this->callApi(
            method: 'GET',
            path: 'query?query=' . \rawurlencode("select Id from Customer where PrimaryEmailAddr = '" . $this->escapeQueryValue($email) . "'"),
        );

        $found = !empty($existing['QueryResponse']['Customer']);
        if ($found) return (string) $existing['QueryResponse']['Customer'][0]['Id'];

        $displayName = $this->cleanDisplayName($name);

        try {
            return $this->createCustomer(displayName: $displayName, email: $email);
        } catch (\Exception $e) {
            $nameClash = \str_contains($e->getMessage(), 'Duplicate Name');
            if (!$nameClash) throw $e;

            return $this->createCustomer(displayName: "{$displayName} ({$email})", email: $email);
        }
    }

    /**
     * DisplayName may not contain colons, tabs or newlines, and is capped at 500 characters.
     */
    private function cleanDisplayName(string $name): string
    {
        $cleaned = \trim(\preg_replace('/[:\t\r\n]+/', ' ', $name));

        return \mb_substr($cleaned, 0, 450);
    }

    private function createCustomer(string $displayName, string $email): string
    {
        $created = $this->callApi(method: 'POST', path: 'customer', body: [
            'DisplayName' => $displayName,
            'PrimaryEmailAddr' => ['Address' => $email],
        ]);

        return (string) $created['Customer']['Id'];
    }

    /**
     * Create a sales invoice for a customer. QuickBooks has no draft/authorised
     * distinction through the API: the invoice is posted as soon as it's created.
     *
     * @param array $lineItems Each item: ['description', 'quantity', 'unitAmount', 'itemId', 'taxCodeId']
     * @return array ['invoiceId', 'invoiceNumber']
     */
    public function createInvoice(
        string $customerId,
        array $lineItems,
        string $reference,
        string $invoiceDate,
        string $dueDate,
    ): array {
        $quickbooksLines = \array_map(static function (array $item): array {
            $detail = [
                'ItemRef' => ['value' => $item['itemId']],
                'Qty' => $item['quantity'],
                'UnitPrice' => $item['unitAmount'],
            ];

            $hasTaxCode = ($item['taxCodeId'] ?? '') !== '';
            if ($hasTaxCode) $detail['TaxCodeRef'] = ['value' => $item['taxCodeId']];

            return [
                'DetailType' => 'SalesItemLineDetail',
                'Description' => $item['description'],
                'Amount' => \round($item['quantity'] * $item['unitAmount'], 2),
                'SalesItemLineDetail' => $detail,
            ];
        }, $lineItems);

        // No DocNumber: QuickBooks numbers invoices itself, and sending one
        // when custom numbering is off can create duplicates. The order
        // reference goes in the private note instead.
        $body = [
            'CustomerRef' => ['value' => $customerId],
            'Line' => $quickbooksLines,
            'TxnDate' => $invoiceDate,
            'DueDate' => $dueDate,
            'PrivateNote' => "Shop order {$reference}",
        ];

        // GlobalTaxCalculation is required for non-US companies and not
        // applicable to US ones; a tax code is only configured for non-US.
        $isNonUs = ($lineItems[0]['taxCodeId'] ?? '') !== '';
        if ($isNonUs) $body['GlobalTaxCalculation'] = 'TaxExcluded';

        $response = $this->callApi(method: 'POST', path: 'invoice', body: $body);

        $invoice = $response['Invoice'];

        return [
            'invoiceId' => (string) $invoice['Id'],
            'invoiceNumber' => $invoice['DocNumber'] ?? null,
        ];
    }

    /**
     * Confirm the connection still works by fetching the company.
     */
    public function testConnection(): array
    {
        try {
            $connection = $this->getConnection();
            $notConnected = $connection === null;
            if ($notConnected) throw new \Exception('QuickBooks is not connected');

            $response = $this->callApi(method: 'GET', path: "companyinfo/{$connection['realm_id']}");

            return [
                'success' => true,
                'message' => 'Connection successful',
                'company' => $response['CompanyInfo']['CompanyName'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Make an authenticated call to the QuickBooks Accounting API, refreshing
     * the access token first if it's about to expire.
     *
     * @throws \Exception On curl failure, malformed JSON, or an API error
     */
    private function callApi(string $method, string $path, ?array $body = null): array
    {
        $auth = $this->ensureValidAccessToken();

        return $this->request(
            method: $method,
            path: $path,
            accessToken: $auth['access_token'],
            realmId: $auth['realm_id'],
            body: $body,
        );
    }

    private function request(string $method, string $path, string $accessToken, string $realmId, ?array $body = null): array
    {
        $host = $this->sandbox ? self::API_HOST_SANDBOX : self::API_HOST_LIVE;
        $separator = \str_contains($path, '?') ? '&' : '?';
        $url = "{$host}/v3/company/{$realmId}/{$path}{$separator}minorversion=" . self::MINOR_VERSION;

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];

        $hasBody = $body !== null;
        if ($hasBody) {
            $options[CURLOPT_POSTFIELDS] = \json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = \curl_init();
        \curl_setopt_array($ch, $options);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);
        $curlError = \curl_error($ch);

        if ($curlError) throw new \Exception("QuickBooks API curl error: {$curlError}");

        $decoded = \json_decode(json: $response, associative: true);

        $jsonDecodeError = \json_last_error() !== JSON_ERROR_NONE;
        if ($jsonDecodeError) throw new \Exception("QuickBooks API returned invalid JSON (HTTP {$httpCode}): {$response}");

        $isError = $httpCode >= 400 || isset($decoded['Fault']);
        if ($isError) {
            $message = $this->extractErrorMessage($decoded) ?? "HTTP {$httpCode}";
            throw new \Exception("QuickBooks API error: {$message}");
        }

        return $decoded;
    }

    /**
     * QuickBooks reports failures as a Fault with a list of Errors, each with
     * a short Message and a more useful Detail.
     */
    private function extractErrorMessage(?array $decoded): ?string
    {
        $errors = $decoded['Fault']['Error'] ?? [];

        $messages = \array_map(
            static fn(array $error): string => \trim(($error['Message'] ?? '') . ': ' . ($error['Detail'] ?? ''), ': '),
            $errors,
        );

        return empty($messages) ? null : \implode('; ', $messages);
    }

    /**
     * Values go into a QuickBooks query string literal, so quotes and
     * backslashes need escaping.
     */
    private function escapeQueryValue(string $value): string
    {
        return \str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
