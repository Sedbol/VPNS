<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2018, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace SURFnet\VPN\Node;

use SURFnet\VPN\Common\Config;
use SURFnet\VPN\Common\ProfileConfig;

class Firewall
{
    /**
     * @return string
     */
    public static function getFirewall4(array $configList, Config $firewallConfig)
    {
        return implode(PHP_EOL, self::getArrayFirewall($configList, $firewallConfig, 4)).PHP_EOL;
    }

    /**
     * @return string
     */
    public static function getFirewall6(array $configList, Config $firewallConfig)
    {
        return implode(PHP_EOL, self::getArrayFirewall($configList, $firewallConfig, 6)).PHP_EOL;
    }

    /**
     * @param int $inetFamily
     *
     * @return array
     */
    private static function getArrayFirewall(array $configList, Config $firewallConfig, $inetFamily)
    {
        $firewall = [];

        // NAT
        $firewall = array_merge(
            $firewall,
             [
            '*nat',
            ':PREROUTING ACCEPT [0:0]',
            ':INPUT ACCEPT [0:0]',
            ':OUTPUT ACCEPT [0:0]',
            ':POSTROUTING ACCEPT [0:0]',
            ]
        );
        // add all instances
        foreach ($configList as $instanceConfig) {
            $firewall = array_merge($firewall, self::getNat($instanceConfig, $inetFamily));
        }
        $firewall[] = 'COMMIT';

        // FILTER
        $firewall = array_merge(
            $firewall,
            [
                '*filter',
                ':INPUT ACCEPT [0:0]',
                ':FORWARD ACCEPT [0:0]',
                ':OUTPUT ACCEPT [0:0]',
            ]
        );

        // INPUT
        $firewall = array_merge($firewall, self::getInputChain($inetFamily, $firewallConfig));

        // FORWARD
        $firewall = array_merge(
            $firewall,
            [
                sprintf('-A FORWARD -p %s -j ACCEPT', 4 === $inetFamily ? 'icmp' : 'ipv6-icmp'),
                '-A FORWARD -m state --state ESTABLISHED,RELATED -j ACCEPT',
            ]
        );

        // add all instances
        foreach ($configList as $instanceConfig) {
            $firewall = array_merge($firewall, self::getForwardChain($instanceConfig, $inetFamily));
        }
        $firewall[] = sprintf('-A FORWARD -j REJECT --reject-with %s', 4 === $inetFamily ? 'icmp-host-prohibited' : 'icmp6-adm-prohibited');
        $firewall[] = 'COMMIT';

        return array_merge(
            [
                '#',
                '# VPN Firewall Configuration',
                '#',
                '# ******************************************',
                '# * THIS FILE IS GENERATED, DO NOT MODIFY! *',
                '# ******************************************',
                '#',
            ],
            $firewall
        );
    }

    /**
     * @param int $inetFamily
     *
     * @return array
     */
    private static function getNat(array $instanceConfig, $inetFamily)
    {
        $nat = [];

        foreach ($instanceConfig['profileList'] as $profileId => $profileData) {
            $profileConfig = new ProfileConfig($profileData);

            if ($profileConfig->hasItem('useNat')) {
                // XXX DEPRECATED, remove for 2.0
                $enableNat4 = $profileConfig->getItem('useNat');
                $enableNat6 = $profileConfig->getItem('useNat');
            } else {
                $enableNat4 = $profileConfig->getItem('enableNat4');
                $enableNat6 = $profileConfig->getItem('enableNat6');
            }

            if ($enableNat4 && 4 === $inetFamily) {
                $srcNet = $profileConfig->getItem('range');
                $nat[] = sprintf('-A POSTROUTING -s %s -o %s -j MASQUERADE', $srcNet, $profileConfig->getItem('extIf'));
            }

            if ($enableNat6 && 6 === $inetFamily) {
                $srcNet = $profileConfig->getItem('range6');
                $nat[] = sprintf('-A POSTROUTING -s %s -o %s -j MASQUERADE', $srcNet, $profileConfig->getItem('extIf'));
            }
        }

        return $nat;
    }

    /**
     * @param int $inetFamily
     *
     * @return array
     */
    private static function getInputChain($inetFamily, Config $firewallConfig)
    {
        $inputChain = [
            '-A INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT',
            sprintf('-A INPUT -p %s -j ACCEPT', 4 === $inetFamily ? 'icmp' : 'ipv6-icmp'),
            '-A INPUT -i lo -j ACCEPT',
        ];

        // add trusted interfaces
        if ($firewallConfig->getSection('inputChain')->hasSection('trustedInterfaces')) {
            foreach ($firewallConfig->getSection('inputChain')->getSection('trustedInterfaces')->toArray() as $trustedIf) {
                $inputChain[] = sprintf('-A INPUT -i %s -j ACCEPT', $trustedIf);
            }
        }

        $udpPorts = $firewallConfig->getSection('inputChain')->getSection('udp')->toArray();
        $tcpPorts = $firewallConfig->getSection('inputChain')->getSection('tcp')->toArray();

        foreach ($udpPorts as $udpPort) {
            if (!\is_array($udpPort)) {
                $inputChain[] = sprintf(
                    '-A INPUT -m state --state NEW -m udp -p udp --dport %s -j ACCEPT',
                    $udpPort
                );

                continue;
            }

            foreach ($udpPort['src'] as $src) {
                $ipSource = new IP($src);
                if ($inetFamily === $ipSource->getFamily()) {
                    $inputChain[] = sprintf(
                        '-A INPUT -m state --state NEW -m udp -p udp --source %s --dport %s -j ACCEPT',
                        $src,
                        $udpPort['port']
                    );
                }
            }
        }

        foreach ($tcpPorts as $tcpPort) {
            if (!\is_array($tcpPort)) {
                $inputChain[] = sprintf(
                    '-A INPUT -m state --state NEW -m tcp -p tcp --dport %s -j ACCEPT',
                    $tcpPort
                );

                continue;
            }

            foreach ($tcpPort['src'] as $src) {
                $ipSource = new IP($src);
                if ($inetFamily === $ipSource->getFamily()) {
                    $inputChain[] = sprintf(
                        '-A INPUT -m state --state NEW -m tcp -p tcp --source %s --dport %s -j ACCEPT',
                        $src,
                        $tcpPort['port']
                    );
                }
            }
        }

        $inputChain[] = sprintf('-A INPUT -j REJECT --reject-with %s', 4 === $inetFamily ? 'icmp-host-prohibited' : 'icmp6-adm-prohibited');

        return $inputChain;
    }

    /**
     * @param int $inetFamily
     *
     * @return array
     */
    private static function getForwardChain(array $instanceConfig, $inetFamily)
    {
        $forwardChain = [];

        $instanceNumber = $instanceConfig['instanceNumber'];
        foreach ($instanceConfig['profileList'] as $profileId => $profileData) {
            $profileConfig = new ProfileConfig($profileData);
            $profileNumber = $profileConfig->getItem('profileNumber');

            if (4 === $inetFamily && $profileConfig->getItem('reject4')) {
                // IPv4 forwarding is disabled
                continue;
            }

            if (6 === $inetFamily && $profileConfig->getItem('reject6')) {
                // IPv6 forwarding is disabled
                continue;
            }

            if (4 === $inetFamily) {
                // get the IPv4 range
                $srcNet = $profileConfig->getItem('range');
            } else {
                // get the IPv6 range
                $srcNet = $profileConfig->getItem('range6');
            }
            $forwardChain[] = sprintf('-N vpn-%s-%s', $instanceNumber, $profileNumber);

            $forwardChain[] = sprintf('-A FORWARD -i tun-%s-%s+ -s %s -j vpn-%s-%s', $instanceNumber, $profileNumber, $srcNet, $instanceNumber, $profileNumber);

            // merge outgoing forwarding firewall rules to prevent certain
            // traffic
            $forwardChain = array_merge($forwardChain, self::getForwardFirewall($instanceNumber, $profileNumber, $profileConfig, $inetFamily));

            if ($profileConfig->getItem('clientToClient')) {
                // allow client-to-client
                $forwardChain[] = sprintf('-A vpn-%s-%s -o tun-%s-%s+ -d %s -j ACCEPT', $instanceNumber, $profileNumber, $instanceNumber, $profileNumber, $srcNet);
            }
            if ($profileConfig->getItem('defaultGateway')) {
                // allow traffic to all outgoing destinations
                $forwardChain[] = sprintf('-A vpn-%s-%s -o %s -j ACCEPT', $instanceNumber, $profileNumber, $profileConfig->getItem('extIf'));
            } else {
                // only allow certain traffic to the external interface
                foreach ($profileConfig->getSection('routes')->toArray() as $route) {
                    $routeIp = new IP($route);
                    if ($inetFamily === $routeIp->getFamily()) {
                        $forwardChain[] = sprintf('-A vpn-%s-%s -o %s -d %s -j ACCEPT', $instanceNumber, $profileNumber, $profileConfig->getItem('extIf'), $route);
                    }
                }
            }
        }

        return $forwardChain;
    }

    /**
     * @param int $instanceNumber
     * @param int $profileNumber
     * @param int $inetFamily
     *
     * @return array
     */
    private static function getForwardFirewall($instanceNumber, $profileNumber, ProfileConfig $profileConfig, $inetFamily)
    {
        $forwardFirewall = [];
        if ($profileConfig->hasItem('blockSmb') && $profileConfig->getItem('blockSmb')) {
            // drop SMB outgoing traffic
            // @see https://medium.com/@ValdikSS/deanonymizing-windows-users-and-capturing-microsoft-and-vpn-accounts-f7e53fe73834
            foreach (['tcp', 'udp'] as $proto) {
                $forwardFirewall[] = sprintf(
                    '-A vpn-%s-%s -o %s -m multiport -p %s --dports 137:139,445 -j REJECT --reject-with %s',
                    $instanceNumber,
                    $profileNumber,
                    $profileConfig->getItem('extIf'),
                    $proto,
                    4 === $inetFamily ? 'icmp-host-prohibited' : 'icmp6-adm-prohibited');
            }
        }

        return $forwardFirewall;
    }
}
