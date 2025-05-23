<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Domain\Utility;

use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;

class IpHelper
{
    private static ?IPAddressValidator $ipValidator = null;

    /**
     * Get the IP validator instance
     *
     * @return IPAddressValidator
     */
    private static function getIPValidator(): IPAddressValidator
    {
        if (self::$ipValidator === null) {
            self::$ipValidator = new IPAddressValidator();
        }
        return self::$ipValidator;
    }

    public static function getProposedIPv4(string $name, string $zoneName, string $suffix): ?string
    {
        $cleanZoneName = str_replace($suffix, '', $zoneName);
        $proposedReverseIP = $name . '.' . $cleanZoneName;
        $ipParts = explode('.', $proposedReverseIP);

        if (count($ipParts) !== 4 || array_filter($ipParts, fn($part) => !is_numeric($part) || $part < 0 || $part > 255)) {
            return null;
        }

        return implode('.', array_reverse($ipParts));
    }

    public static function getProposedIPv6(string $name, string $zoneName, string $suffix): ?string
    {
        $cleanZoneName = str_replace($suffix, '', $zoneName);
        $proposedReverseIP = $name . '.' . $cleanZoneName;
        $ipParts = explode('.', $proposedReverseIP);

        if (count($ipParts) !== 32 || array_filter($ipParts, fn($part) => !ctype_xdigit($part) || strlen($part) !== 1)) {
            return null;
        }

        $reversedIpParts = array_reverse($ipParts);
        $ipv6 = implode('', $reversedIpParts);
        $ipv6 = preg_replace('/([0-9a-f]{4})/', '$1:', $ipv6);
        $ipv6 = rtrim($ipv6, ':');

        $ipValidator = self::getIPValidator();
        if (!$ipValidator->isValidIPv6($ipv6)) {
            return null;
        }

        return inet_ntop(inet_pton($ipv6));
    }

    /**
     * Expand an IPv6 address to its full form
     *
     * @param string $ip IPv6 address, potentially in compressed form
     * @return string Expanded IPv6 address
     */
    public static function expandIPv6(string $ip): string
    {
        $binary = inet_pton($ip);
        if ($binary === false) {
            return '';
        }
        $hex = bin2hex($binary);

        // Format as 8 groups of 4 hex digits
        $parts = [];
        for ($i = 0; $i < 8; $i++) {
            $parts[] = substr($hex, $i * 4, 4);
        }

        return implode(':', $parts);
    }

    /**
     * Convert an IPv6 address to its PTR record form
     *
     * @param string $ip IPv6 address
     * @return string PTR record form
     */
    public static function convertIPv6ToPTR(string $ip): string
    {
        // Clean and normalize the IPv6 address
        if (str_contains($ip, '::')) {
            // If it's a compressed IPv6 address, expand it
            $binary = inet_pton($ip);
            if ($binary === false) {
                return '';
            }
            $hex = bin2hex($binary);
        } else {
            // If it's already expanded, just remove colons
            $hex = str_replace(':', '', $ip);
        }

        // Reverse the hex digits and separate with dots
        $nibbles = str_split($hex);
        $reversed = implode('.', array_reverse($nibbles));

        // Add the ip6.arpa suffix
        return $reversed . '.ip6.arpa';
    }

    /**
     * Build the reverse domain for an IPv4 address with appropriate CIDR handling
     *
     * @param array $octets The IP address octets
     * @param int $cidr The CIDR mask length
     * @return string The reverse domain name
     */
    public static function buildReverseIPv4Domain(array $octets, int $cidr): string
    {
        // For different CIDR ranges, we need different reverse zones
        // /24 = third octet
        // /23-/17 = second octet
        // /16-/9 = first octet
        // /8-/1 = in-addr.arpa directly

        if ($cidr >= 24) { // /24 or more specific
            return $octets[3] . '.' . $octets[2] . '.' . $octets[1] . '.' . $octets[0] . '.in-addr.arpa';
        } elseif ($cidr >= 16) { // /23 through /16
            return $octets[2] . '.' . $octets[1] . '.' . $octets[0] . '.in-addr.arpa';
        } elseif ($cidr >= 8) { // /15 through /8
            return $octets[1] . '.' . $octets[0] . '.in-addr.arpa';
        } else { // /7 through /0
            return $octets[0] . '.in-addr.arpa';
        }
    }

    /**
     * Get IPv6 reverse zone for a network prefix
     *
     * @param string $networkPrefix The IPv6 network prefix (e.g., "2001:db8:1:1")
     * @return string The reverse zone (e.g., "1.1.0.0.8.b.d.0.1.0.0.2.ip6.arpa")
     */
    public static function getIPv6ReverseZone(string $networkPrefix): string
    {
        // Add zeros to form a complete IPv6 address
        $fullAddress = $networkPrefix . '::';

        // Expand to full form
        $expanded = self::expandIPv6($fullAddress);
        $noColons = str_replace(':', '', $expanded);

        // For a /64 network, we need the first 16 hex digits (64 bits)
        $networkPart = substr($noColons, 0, 16);

        // Reverse and add dots
        $nibbles = str_split($networkPart);
        $reversed = implode('.', array_reverse($nibbles));

        return $reversed . '.ip6.arpa';
    }
}
