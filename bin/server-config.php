#!/usr/bin/php
<?php
/**
 *  Copyright (C) 2016 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
require_once sprintf('%s/vendor/autoload.php', dirname(__DIR__));

use SURFnet\VPN\Common\Config;
use SURFnet\VPN\Node\OpenVpn;
use SURFnet\VPN\Common\CliParser;
use SURFnet\VPN\Common\ProfileConfig;
use SURFnet\VPN\Common\HttpClient\GuzzleHttpClient;
use SURFnet\VPN\Common\HttpClient\CaClient;
use SURFnet\VPN\Common\HttpClient\ServerClient;

try {
    $p = new CliParser(
        'Generate VPN server configuration for an instance',
        [
            'instance' => ['the instance', true, true],
            'profile' => ['the profile identifier', true, true],
            'generate' => ['generate a new certificate for the server', false, false],
            'cn' => ['the CN of the certificate to generate', true, false],
        ]
    );

    $opt = $p->parse($argv);
    if ($opt->e('help')) {
        echo $p->help();
        exit(0);
    }

    $instanceId = $opt->v('instance');
    $profileId = $opt->v('profile');
    $generateCerts = $opt->e('generate');

    $configFile = sprintf('%s/config/%s/config.yaml', dirname(__DIR__), $instanceId);
    $config = Config::fromFile($configFile);

    $vpnUser = $config->e('vpnUser') ? $config->v('vpnUser') : 'openvpn';
    $vpnGroup = $config->e('vpnGroup') ? $config->v('vpnGroup') : 'openvpn';

    $vpnConfigDir = sprintf('%s/openvpn-config', dirname(__DIR__));
    $vpnTlsDir = sprintf('%s/openvpn-config/tls/%s/%s', dirname(__DIR__), $instanceId, $profileId);

    $serverClient = new ServerClient(
        new GuzzleHttpClient(
            [
                'defaults' => [
                    'auth' => [
                        $config->v('apiProviders', 'vpn-server-api', 'userName'),
                        $config->v('apiProviders', 'vpn-server-api', 'userPass'),
                    ],
                ],
            ]
        ),
        $config->v('apiProviders', 'vpn-server-api', 'apiUri')
    );

    $instanceConfig = $serverClient->instanceConfig();
    $instanceNumber = $instanceConfig['instanceNumber'];

    $profileConfigData = $serverClient->serverProfile($profileId);
    $profileConfigData['_user'] = $vpnUser;
    $profileConfigData['_group'] = $vpnGroup;
    $profileConfig = new ProfileConfig($profileConfigData);

    $o = new OpenVpn($vpnConfigDir, $vpnTlsDir);
    $o->writeProfile($instanceNumber, $instanceId, $profileId, $profileConfig);
    if ($generateCerts) {
        $caClient = new CaClient(
            new GuzzleHttpClient(
                [
                    'defaults' => [
                        'auth' => [
                            $config->v('apiProviders', 'vpn-ca-api', 'userName'),
                            $config->v('apiProviders', 'vpn-ca-api', 'userPass'),
                        ],
                    ],
                ]
            ),
            $config->v('apiProviders', 'vpn-ca-api', 'apiUri')
        );
        $dhSourceFile = sprintf('%s/config/dh.pem', dirname(__DIR__));
        $o->generateKeys($caClient, $opt->v('cn'), $dhSourceFile);
    }
} catch (Exception $e) {
    echo sprintf('ERROR: %s', $e->getMessage()).PHP_EOL;
    exit(1);
}
