<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2018, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace SURFnet\VPN\Node;

use InvalidArgumentException;
use SURFnet\VPN\Node\Exception\IPException;

class IP
{
    /** @var string */
    private $ipAddress;

    /** @var int */
    private $ipPrefix;

    /** @var int */
    private $ipFamily;

    /**
     * @param string $ipAddressPrefix
     */
    public function __construct($ipAddressPrefix)
    {
        // detect if there is a prefix
        $hasPrefix = false !== mb_strpos($ipAddressPrefix, '/');
        if ($hasPrefix) {
            list($ipAddress, $ipPrefix) = explode('/', $ipAddressPrefix);
        } else {
            $ipAddress = $ipAddressPrefix;
            $ipPrefix = null;
        }

        // validate the IP address
        if (false === filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            throw new IPException('invalid IP address');
        }

        $is6 = false !== mb_strpos($ipAddress, ':');
        if ($is6) {
            if (null === $ipPrefix) {
                $ipPrefix = 128;
            }

            if (!is_numeric($ipPrefix) || 0 > $ipPrefix || 128 < $ipPrefix) {
                throw new IPException('IP prefix must be a number between 0 and 128');
            }
            // normalize the IPv6 address
            $ipAddress = inet_ntop(inet_pton($ipAddress));
        } else {
            if (null === $ipPrefix) {
                $ipPrefix = 32;
            }
            if (!is_numeric($ipPrefix) || 0 > $ipPrefix || 32 < $ipPrefix) {
                throw new IPException('IP prefix must be a number between 0 and 32');
            }
        }

        $this->ipAddress = $ipAddress;
        $this->ipPrefix = (int) $ipPrefix;
        $this->ipFamily = $is6 ? 6 : 4;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getAddressPrefix();
    }

    /**
     * @return string
     */
    public function getAddress()
    {
        return $this->ipAddress;
    }

    /**
     * @return int
     */
    public function getPrefix()
    {
        return $this->ipPrefix;
    }

    /**
     * @return string
     */
    public function getAddressPrefix()
    {
        return sprintf('%s/%d', $this->getAddress(), $this->getPrefix());
    }

    /**
     * @return int
     */
    public function getFamily()
    {
        return $this->ipFamily;
    }

    /**
     * IPv4 only.
     *
     * @return string
     */
    public function getNetmask()
    {
        $this->requireIPv4();

        return long2ip(-1 << (32 - $this->getPrefix()));
    }

    /**
     * IPv4 only.
     *
     * @return string
     */
    public function getNetwork()
    {
        $this->requireIPv4();

        return long2ip(ip2long($this->getAddress()) & ip2long($this->getNetmask()));
    }

    /**
     * IPv4 only.
     *
     * @return int
     */
    public function getNumberOfHosts()
    {
        $this->requireIPv4();

        return (int) pow(2, 32 - $this->getPrefix()) - 2;
    }

    /**
     * @param int $networkCount
     *
     * @return array<IP>
     */
    public function split($networkCount)
    {
        if (0 !== ($networkCount & ($networkCount - 1))) {
            throw new InvalidArgumentException('parameter must be power of 2');
        }

        if (4 === $this->getFamily()) {
            return $this->split4($networkCount);
        }

        return $this->split6($networkCount);
    }

    /**
     * @return string
     */
    public function getFirstHost()
    {
        if (4 === $this->ipFamily && 31 <= $this->ipPrefix) {
            throw new IPException('network not big enough');
        }
        if (6 === $this->ipFamily && 127 <= $this->ipPrefix) {
            throw new IPException('network not big enough');
        }

        $hexIp = bin2hex(inet_pton($this->ipAddress));
        $lastDigit = hexdec(substr($hexIp, -1));
        $hexIp = substr_replace($hexIp, $lastDigit + 1, -1);

        return inet_ntop(hex2bin($hexIp));
    }

    /**
     * @param int $networkCount
     *
     * @return array<IP>
     */
    private function split4($networkCount)
    {
        if (pow(2, 32 - $this->getPrefix() - 2) < $networkCount) {
            throw new IPException('network too small to split in this many networks');
        }

        $prefix = $this->getPrefix() + log($networkCount, 2);
        $splitRanges = [];
        for ($i = 0; $i < $networkCount; ++$i) {
            $noHosts = pow(2, 32 - $prefix);
            $networkAddress = long2ip((int) ($i * $noHosts + ip2long($this->getAddress())));
            $splitRanges[] = new self($networkAddress.'/'.$prefix);
        }

        return $splitRanges;
    }

    /**
     * @param int $networkCount
     *
     * @return array<IP>
     */
    private function split6($networkCount)
    {
        // we will ALWAYS assign a /112 to every OpenVPN process. So we need
        // at least /108 or bigger to be able to accomodate 16 OpenVPN
        // processes. OpenVPN does not support assigning anything smaller than
        // a /112 to an OpenVPN process
        if (112 < $this->getPrefix()) {
            throw new IPException('network too small, must be >= /112');
        }
        if (1 !== $networkCount) {
            if (108 < $this->getPrefix()) {
                throw new IPException('network too small to split up, must be >= /108');
            }
        }

        if (0 !== $this->getPrefix() % 4) {
            throw new IPException('network prefix length must be divisible by 4');
        }

        $hexAddress = bin2hex(inet_pton($this->getAddress()));
        // clear the last 20 bits (128 - 108)
        $hexAddress = substr_replace($hexAddress, '00000', -5);
        $splitRanges = [];
        for ($i = 0; $i < $networkCount; ++$i) {
            $hexAddress[27] = dechex($i);
            $splitRanges[] = new self(
                sprintf('%s/112', inet_ntop(hex2bin($hexAddress)))
            );
        }

        return $splitRanges;
    }

    /**
     * @return void
     */
    private function requireIPv4()
    {
        if (4 !== $this->getFamily()) {
            throw new IPException('method only for IPv4');
        }
    }
}
