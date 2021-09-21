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
     * @param array<string,null|string> $postData
     */
    public function post(string $requestUrl, array $postData): HttpClientResponse;
}
