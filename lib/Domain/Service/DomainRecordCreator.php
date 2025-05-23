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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\IpHelper;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class DomainRecordCreator
{
    private ConfigurationManager $config;
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;
    private IPAddressValidator $ipValidator;

    private const IPV4_SUFFIX = '.in-addr.arpa';
    private const IPV6_SUFFIX = '.ip6.arpa';

    public function __construct(
        ConfigurationManager $config,
        LegacyLogger $logger,
        DnsRecord $dnsRecord,
        ?IPAddressValidator $ipValidator = null,
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->dnsRecord = $dnsRecord;
        $this->ipValidator = $ipValidator ?? new IPAddressValidator();
    }

    public function addDomainRecord(string $name, string $type, string $content, string $zone_id, string $comment = '', string $account = ''): array
    {
        $iface_add_domain_record = $this->config->get('interface', 'add_domain_record');

        $registeredDomain = DnsHelper::getRegisteredDomain($content);
        $domainId = $this->dnsRecord->getDomainIdByName($registeredDomain);
        if ($domainId === null) {
            return $this->errorResponse(sprintf(_('There is no managed zone for domain: %s.'), $content));
        }

        if ($name && $iface_add_domain_record && $type === 'PTR') {
            $zone_name = $this->dnsRecord->getDomainNameById($zone_id);

            if (str_ends_with($zone_name, self::IPV4_SUFFIX)) {
                return $this->processIPv4($name, $zone_name, $content, $domainId, $comment, $account);
            }

            if (str_ends_with($zone_name, self::IPV6_SUFFIX)) {
                // FIXME: not fully implemented and tested
                // return $this->processIPv6($name, $zone_name, $content, $domainId);
                return $this->errorResponse(_('Adding IPv6 domain records from reverse zones is not supported yet.'));
            }
        }

        return $this->errorResponse(_('This domain record was not valid and could not be added.'));
    }

    private function processIPv4(string $name, string $zone_name, string $content, int $domainId, string $comment, string $account): array
    {
        $proposedIP = IpHelper::getProposedIPv4($name, $zone_name, self::IPV4_SUFFIX);
        if ($proposedIP && $this->ipValidator->isValidIPv4($proposedIP)) {
            return $this->addRecord($domainId, $content, $proposedIP, $comment, $account);
        }
        return $this->errorResponse(_('This domain record was not valid and could not be added.'));
    }

    private function processIPv6(string $name, string $zone_name, string $content, int $domainId, string $comment, string $account): array
    {
        $proposedIP = IpHelper::getProposedIPv6($name, $zone_name, self::IPV6_SUFFIX);
        if ($proposedIP && $this->ipValidator->isValidIPv6($proposedIP)) {
            return $this->addRecord($domainId, $content, $proposedIP, $comment, $account);
        }
        return $this->errorResponse(_('This domain record was not valid and could not be added.'));
    }

    private function addRecord(int $domainId, string $content, string $proposedIP, string $comment, string $account): array
    {
        $domainName = DnsHelper::getSubDomainName($content);
        $result = $this->dnsRecord->addRecord($domainId, $domainName, RecordType::A, $proposedIP, $this->config->get('dns', 'ttl'), 0);

        if ($result) {
            return [
                'success' => true,
                'type' => 'success',
                'message' => _('The domain record was successfully added.')
            ];
        }

        return $this->errorResponse(_('This domain record was not valid and could not be added.'));
    }

    private function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'type' => 'error',
            'message' => $message
        ];
    }
}
