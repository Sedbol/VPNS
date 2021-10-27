<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Node\HttpClient;

use LC\Node\Binary;
use LC\Node\HttpClient\Exception\HttpClientException;
use RuntimeException;

class CurlHttpClient implements HttpClientInterface
{
    /** @var array<string> */
    private array $requestHeaderList = [];

    public function setRequestHeader(string $headerName, string $headerValue): void
    {
        $this->requestHeaderList[] = $headerName.': '.$headerValue;
    }

    public function send(HttpClientRequest $httpClientRequest): HttpClientResponse
    {
        if (false === $curlChannel = curl_init()) {
            throw new RuntimeException('unable to create cURL channel');
        }

        $headerList = '';
        $curlOptions = [
            CURLOPT_URL => $httpClientRequest->requestUrl(),
            CURLOPT_HTTPHEADER => array_merge($this->requestHeaderList, $httpClientRequest->requestHeaders()),
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION =>
            /**
             * @param resource $curlChannel
             */
            function ($curlChannel, string $headerLine) use (&$headerList): int {
                $headerList .= $headerLine;

                return Binary::safeStrlen($headerLine);
            },
        ];

        if ('POST' === $httpClientRequest->requestMethod()) {
            $curlOptions[CURLOPT_POSTFIELDS] = $httpClientRequest->postParameters();
        }

        if (false === curl_setopt_array($curlChannel, $curlOptions)) {
            throw new RuntimeException('unable to set cURL options');
        }

        $responseData = curl_exec($curlChannel);
        if (!\is_string($responseData)) {
            throw new HttpClientException($httpClientRequest, null, sprintf('failure performing the HTTP request: "%s"', curl_error($curlChannel)));
        }

        $responseCode = (int) curl_getinfo($curlChannel, CURLINFO_HTTP_CODE);
        curl_close($curlChannel);

        $httpClientResponse = new HttpClientResponse(
            $responseCode,
            $headerList,
            $responseData
        );

        if (!$httpClientResponse->isOkay()) {
            throw new HttpClientException($httpClientRequest, $httpClientResponse, 'unexpected HTTP response code ('.$responseCode.')');
        }

        return $httpClientResponse;
    }
}
