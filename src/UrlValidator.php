<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Validates the configured engine URL to mitigate SSRF risk.
 *
 * Default policy: reject URLs whose host resolves to a private, loopback,
 * link-local, or carrier-grade-NAT IP range. An admin can opt out of the
 * check by setting the WP option `opensalestax_allow_private_nets` to "1"
 * or defining the constant `OPENSALESTAX_ALLOW_PRIVATE_NETS` to true —
 * appropriate when self-hosting the engine on the same LAN as WordPress.
 *
 * Threat model: a compromised admin (or insider) could otherwise point
 * the plugin at internal services (cloud metadata endpoints, internal
 * APIs) and trigger requests on behalf of the WordPress server. The
 * `manage_woocommerce` capability already gates URL changes, so this
 * is defense-in-depth for the case where that capability is broader
 * than the merchant intends.
 *
 * Documented in `docs/SECURITY-REVIEW.md` (CWE-918).
 */
final class UrlValidator
{
    /**
     * Result codes for `validate()`.
     */
    public const OK = 'ok';
    public const BAD_FORMAT = 'bad_format';
    public const BAD_SCHEME = 'bad_scheme';
    public const PRIVATE_HOST_BLOCKED = 'private_host_blocked';
    public const HOST_UNRESOLVABLE = 'host_unresolvable';

    /**
     * @return array{code: string, message: string} `code` is one of the constants above;
     *                                              `message` is a human-readable explanation.
     */
    public static function validate(string $url): array
    {
        if ($url === '') {
            return ['code' => self::BAD_FORMAT, 'message' => 'URL is empty.'];
        }

        $parts = wp_parse_url($url);
        if ($parts === false) {
            return ['code' => self::BAD_FORMAT, 'message' => 'URL is malformed.'];
        }

        // Check scheme before host so callers see "scheme not supported" rather
        // than "missing host" for cases like `file:///etc/passwd` (which has a
        // path but no host in URL parse semantics).
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($scheme === '') {
            return ['code' => self::BAD_FORMAT, 'message' => 'URL must include a scheme (e.g. http://engine:8080).'];
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['code' => self::BAD_SCHEME, 'message' => "Only http:// and https:// are supported (got '{$scheme}://')."];
        }

        if (!isset($parts['host'])) {
            return ['code' => self::BAD_FORMAT, 'message' => 'URL must include a host (e.g. http://engine:8080).'];
        }
        $host = (string) $parts['host'];

        // Resolve host to IP — host may already be an IP or may be a hostname.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ip = $host;
        } else {
            $resolved = gethostbyname($host);
            if ($resolved === $host) {
                // gethostbyname returns the original input on failure
                return ['code' => self::HOST_UNRESOLVABLE, 'message' => "Could not resolve host '{$host}'."];
            }
            $ip = $resolved;
        }

        if (self::isPrivateRangeIp($ip) && !self::privateNetsAllowed()) {
            return [
                'code' => self::PRIVATE_HOST_BLOCKED,
                'message' => "Host '{$host}' resolves to a private/internal IP ({$ip}). "
                    . 'If your engine runs on the same LAN as WordPress, set the WP option '
                    . '`opensalestax_allow_private_nets` to "1" or define the constant '
                    . '`OPENSALESTAX_ALLOW_PRIVATE_NETS=true` in wp-config.php to allow this.',
            ];
        }

        return ['code' => self::OK, 'message' => 'OK'];
    }

    /**
     * True if the IP falls in a private, loopback, link-local, or
     * carrier-grade NAT range.
     */
    public static function isPrivateRangeIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE rejects 10/8, 172.16/12, 192.168/16, fc00::/7, fec0::/10
        // FILTER_FLAG_NO_RES_RANGE rejects 0.0.0.0, 127/8, 169.254/16, 224/4, 240/4, ::1, fe80::/10
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public !== false) {
            // Manual carrier-grade NAT check: 100.64.0.0/10
            if (self::isInCgnatRange($ip)) {
                return true;
            }
            return false;
        }
        return true;
    }

    /**
     * Carrier-grade NAT range 100.64.0.0/10 (RFC 6598).
     * filter_var doesn't classify this as private, but it's used for
     * ISP-internal addressing and shouldn't be a destination from a
     * WordPress server in any normal deployment.
     */
    private static function isInCgnatRange(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $cgnatStart = ip2long('100.64.0.0');
        $cgnatEnd = ip2long('100.127.255.255');
        if ($cgnatStart === false || $cgnatEnd === false) {
            return false;
        }
        return $long >= $cgnatStart && $long <= $cgnatEnd;
    }

    /**
     * True if the admin has opted into allowing private/loopback/link-local
     * destinations. Honored via either `OPENSALESTAX_ALLOW_PRIVATE_NETS`
     * defined in wp-config.php OR the WP option of the same name set to "1".
     */
    public static function privateNetsAllowed(): bool
    {
        if (defined('OPENSALESTAX_ALLOW_PRIVATE_NETS') && constant('OPENSALESTAX_ALLOW_PRIVATE_NETS') === true) {
            return true;
        }
        $opt = get_option('opensalestax_allow_private_nets', '0');
        return $opt === '1' || $opt === 1 || $opt === true;
    }
}
