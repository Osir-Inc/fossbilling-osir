<?php

declare(strict_types=1);

namespace Osir\FossBilling\Config;

use Osir\FossBilling\Exception\ConfigurationException;

/**
 * Turns FOSSBilling's stored registrar settings plus server-level overrides into {@see Settings}.
 *
 * Precedence for the API key (highest first):
 *   1. PHP constant  OSIR_REGISTRAR_API_KEY (e.g. in config.php)
 *   2. environment   same names (web SAPI only reliably; FOSSBilling's cron may not inherit it)
 *   3. the value saved in FOSSBilling's registrar settings (stored in the database)
 * Options 1 and 2 keep the key out of the database and its backups, which is recommended.
 *
 * The API base URL is NOT an admin setting: an attacker with admin-panel access must not be able
 * to redirect the key to a host they control. It can only be overridden at server level
 * (constant or environment OSIR_REGISTRAR_API_URL), for OSIR staging and the test suite, and the
 * override must still be https.
 */
final class SettingsResolver
{
    private const string KEY_PATTERN = '/^osir_(live|test)_[A-Za-z0-9_-]{16,128}$/';

    /**
     * @param \Closure(string): (string|null) $lookup reads a constant or environment variable by name
     */
    public function __construct(private readonly \Closure $lookup) {}

    /**
     * Where each server-level setting may also live inside FOSSBilling's own configuration array
     * (config.php returns it). Unlike a define() added to config.php, entries in that array survive
     * FOSSBilling rewriting config.php (updates, some admin settings), because it writes the whole
     * array back.
     */
    private const array CONFIG_PROPERTIES = [
        'OSIR_REGISTRAR_API_KEY' => 'osir.api_key',
    ];

    /**
     * Resolver backed by, in order: PHP constants, environment variables, then FOSSBilling's
     * configuration array (`'osir' => ['api_key' => …]`).
     *
     * @param (\Closure(string): mixed)|null $configProperty reads a dotted FOSSBilling config property (tests inject one)
     */
    public static function fromRuntime(?\Closure $configProperty = null): self
    {
        $configProperty ??= static function (string $path): mixed {
            if (!defined('PATH_CONFIG') || !class_exists(\FOSSBilling\Config::class)) {
                return null;
            }

            try {
                return \FOSSBilling\Config::getProperty($path);
            } catch (\Throwable) {
                return null;
            }
        };

        return new self(static function (string $name) use ($configProperty): ?string {
            if (defined($name)) {
                $value = constant($name);

                return is_scalar($value) ? (string) $value : null;
            }
            $env = getenv($name);
            if (is_string($env) && $env !== '') {
                return $env;
            }
            $property = self::CONFIG_PROPERTIES[$name] ?? null;
            $value = $property !== null ? $configProperty($property) : null;

            return is_string($value) && $value !== '' ? $value : null;
        });
    }

    /**
     * @param array<array-key, mixed> $config FOSSBilling's stored adapter configuration
     *
     * @throws ConfigurationException
     */
    public function resolve(array $config, bool $testMode, string $installationSeed): Settings
    {
        if ($testMode) {
            // OSIR has no sandbox. Refusing here, before any request, means Test Mode can never
            // silently turn into live, paid operations.
            throw new ConfigurationException('OSIR has no test environment. Turn off Test Mode for this registrar (Domain Registration → Registrars → OSIR); while it is on, nothing is sent to OSIR.');
        }
        $environment = Environment::Live;
        [$apiKey, $source] = $this->resolveApiKey($config);

        return new Settings(
            environment: $environment,
            apiKey: $apiKey,
            baseUrl: $this->resolveBaseUrl(),
            caFile: $this->resolveCaFile(),
            installationId: substr(hash('sha256', 'osir-fossbilling|' . $installationSeed), 0, 12),
            maxYearlyCostCents: self::parseMaxCost($config['max_yearly_cost'] ?? null),
            initializeDnsZone: self::parseBool($config['initialize_dns_zone'] ?? '0'),
            debug: self::parseBool($config['debug_logging'] ?? '0'),
            source: $source,
        );
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array{0: Secret, 1: string}
     */
    private function resolveApiKey(array $config): array
    {
        $value = $this->lookup('OSIR_REGISTRAR_API_KEY');
        $source = 'server';
        if ($value === null || trim($value) === '') {
            $stored = $config['api_key'] ?? null;
            $value = is_string($stored) ? $stored : null;
            $source = 'settings';
        }

        $value = trim((string) $value);
        if ($value === '') {
            throw new ConfigurationException(
                is_string($config['api_key_test'] ?? null) && $config['api_key_test'] !== ''
                    ? 'No OSIR API key (osir_live_…) is configured. The sandbox key saved by version 1.0.x is no longer used: OSIR has no test environment.'
                    : 'No OSIR API key (osir_live_…) is configured.',
            );
        }
        if (preg_match(self::KEY_PATTERN, $value) !== 1) {
            throw new ConfigurationException('The configured OSIR API key is malformed. Copy it again from the OSIR panel.');
        }
        if (!str_starts_with($value, Environment::Live->keyPrefix())) {
            throw new ConfigurationException('Only live OSIR API keys (starting with osir_live_) are supported.');
        }

        return [new Secret($value), $source];
    }

    private function resolveBaseUrl(): string
    {
        $override = $this->lookup('OSIR_REGISTRAR_API_URL');
        if ($override === null || trim($override) === '') {
            return Settings::DEFAULT_BASE_URL;
        }

        $url = rtrim(trim($override), '/');
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
        ) {
            throw new ConfigurationException('OSIR_REGISTRAR_API_URL must be an https URL with no path, query or credentials.');
        }

        return $url;
    }

    private function resolveCaFile(): ?string
    {
        $path = $this->lookup('OSIR_REGISTRAR_CA_FILE');
        if ($path === null || trim($path) === '') {
            return null;
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigurationException('OSIR_REGISTRAR_CA_FILE does not point to a readable file.');
        }

        return $path;
    }

    private function lookup(string $name): ?string
    {
        return ($this->lookup)($name);
    }

    private static function parseMaxCost(mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $cents = is_numeric($value) ? (int) round((float) $value * 100) : 0;
        if ($cents < 1 || $cents > 100_000_000) {
            throw new ConfigurationException('"Maximum cost per year" must be an amount in USD of at least 0.01, or empty.');
        }

        return $cents;
    }

    private static function parseBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'on', 'true'], true);
    }
}
