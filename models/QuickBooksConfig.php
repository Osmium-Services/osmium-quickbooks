<?php

declare(strict_types=1);

namespace Osmium\Services\QuickBooks\Models;

/**
 * QuickBooks configuration.
 *
 * File-based config (app/config/services/quickbooks.json.php), matching the
 * Stripe/PayPal/Analytics convention. The client secret is a secret: the
 * settings page never echoes it back, and a blank submission keeps the
 * stored value.
 */
class QuickBooksConfig
{
    private static ?object $config = null;
    private static string $configPath = 'app/config/services/quickbooks.json.php';

    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) {
            self::$config = self::defaults();
            return self::$config;
        }

        $content = \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');

        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) {
            self::$config = self::defaults();
            return self::$config;
        }

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));
        $stored = (array) ($decoded->quickbooks ?? []);

        self::$config = (object) ($stored + (array) self::defaults());

        return self::$config;
    }

    public static function save(array $values): void
    {
        $dir = \dirname(self::$configPath);
        $dirExists = \is_dir($dir);
        if (!$dirExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['quickbooks' => $values],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::$configPath, "<?php exit(); ?>\n" . $json . "\n");
        self::clearCache();
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    public static function buildService(\Osmium\Core\Library\OsmiumPDO $database): QuickBooksService
    {
        $config = self::get();

        return new QuickBooksService(
            clientId: (string) $config->clientId,
            clientSecret: (string) $config->clientSecret,
            redirectUri: (string) $config->redirectUri,
            sandbox: (bool) $config->sandbox,
            database: $database,
        );
    }

    private static function defaults(): object
    {
        return (object) [
            'clientId' => '',
            'clientSecret' => '',
            'redirectUri' => '',
            'salesItemId' => '1',
            'taxCodeId' => '',
            'sandbox' => false,
            'allowedCompanyName' => '',
        ];
    }
}
