<?php

namespace App\Services\Security;

use InvalidArgumentException;

class UrlSecurityValidator
{
    /**
     * Blocked internal hostnames and service names in containerized/cloud environments.
     */
    protected static array $blockedHostnames = [
        'localhost',
        '127.0.0.1',
        '::1',
        '0.0.0.0',
        'redis',
        'mysql',
        'backend',
        'frontend',
        'baileys',
        'nginx',
        'reverb',
        'queue',
        'scheduler',
        'host.docker.internal',
        'metadata.google.internal',
        'instance-data',
    ];

    /**
     * Assert that a URL is safe against Server-Side Request Forgery (SSRF).
     *
     * @param string $url
     * @throws InvalidArgumentException
     */
    public static function assertSafeUrl(string $url): void
    {
        if (!self::isSafeUrl($url, $error)) {
            throw new InvalidArgumentException($error ?: 'Destination URL is not permitted for security reasons.');
        }
    }

    /**
     * Validate whether a URL is safe to dispatch outbound HTTP requests to.
     *
     * @param string $url
     * @param string|null $error Reason for rejection
     * @return bool
     */
    public static function isSafeUrl(string $url, ?string &$error = null): bool
    {
        $trimmed = trim($url);
        if (empty($trimmed)) {
            $error = 'URL cannot be empty.';
            return false;
        }

        // Validate scheme
        $scheme = strtolower((string)parse_url($trimmed, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $error = "Invalid URL scheme '{$scheme}'. Only HTTP and HTTPS protocols are allowed.";
            return false;
        }

        // Validate host
        $host = parse_url($trimmed, PHP_URL_HOST);
        if (empty($host)) {
            $error = 'Invalid destination host in URL.';
            return false;
        }

        $hostLower = strtolower($host);

        // Check against blocked hostname list
        foreach (self::$blockedHostnames as $blocked) {
            if ($hostLower === $blocked || str_ends_with($hostLower, '.' . $blocked)) {
                $error = "Access to internal hostname '{$host}' is restricted.";
                return false;
            }
        }

        // Block .local, .internal, .localhost TLDs
        if (preg_match('/\.(local|internal|localhost|test|example|invalid)$/i', $hostLower)) {
            $error = "Access to private domain '{$host}' is restricted.";
            return false;
        }

        // Resolve IP addresses for the hostname
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = @gethostbynamel($host);
            if ($resolved && is_array($resolved)) {
                $ips = array_merge($ips, $resolved);
            }
            
            // Check IPv6 DNS records
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($host, DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $rec) {
                        if (!empty($rec['ipv6'])) {
                            $ips[] = $rec['ipv6'];
                        }
                    }
                }
            }
        }

        if (empty($ips)) {
            $error = "Could not resolve IP address for hostname '{$host}'.";
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                $error = "Destination IP address '{$ip}' is within a private, loopback, or cloud metadata network.";
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an IP address is a publicly routable global IP.
     */
    public static function isPublicIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE: Rejects 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, etc.
        // FILTER_FLAG_NO_RES_RANGE: Rejects 0.0.0.0/8, 169.254.0.0/16, 127.0.0.0/8, 240.0.0.0/4, etc.
        $isValidPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isValidPublic === false) {
            return false;
        }

        // Explicitly reject AWS/GCP/Azure link-local metadata address 169.254.169.254
        if (str_starts_with($ip, '169.254.')) {
            return false;
        }

        // Reject IPv6 Link-Local and Loopback
        if ($ip === '::1' || str_starts_with(strtolower($ip), 'fe80:') || str_starts_with(strtolower($ip), 'fc00:') || str_starts_with(strtolower($ip), 'fd00:')) {
            return false;
        }

        return true;
    }
}
