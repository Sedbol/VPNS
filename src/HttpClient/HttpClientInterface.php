<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Node\HttpClient;

interface HttpClientInterface
{
    /**
     * @param array<string,string> $queryParameters
     * @param array<string>        $requestHeaders
     */
    public function get(string $requestUrl, array $queryParameters, array $requestHeaders = []): HttpClientResponse;

    /**
     * @param array<string,string> $queryParameters
     * @param array<string,string> $postData
     * @param array<string>        $requestHeaders
     */
    public function post(string $requestUrl, array $queryParameters, array $postData, array $requestHeaders = []): HttpClientResponse;

    /**
     * @param array<string,string> $queryParameters
     * @param array<string>        $requestHeaders
     */
    public function postRaw(string $requestUrl, array $queryParameters, string $rawPost, array $requestHeaders = []): HttpClientResponse;
}
