<?php

// create array of query params

$queryArray = [];
parse_str($_SERVER['QUERY_STRING'], $queryArray);

// get target url

$url = $queryArray['url'];
unset($queryArray['url']);

// [custom_param] request url protocol

$urlProtocol = $queryArray['__rq_p'] ?? 'https';
unset($queryArray['__rq_p']);

# --------------- Request Headers Params ---------------------

// [custom_param] request headers

$requestHeadersMod = $queryArray['__rq_h'] ?? [];
unset($queryArray['__rq_h']);

# Request Header Shortcuts

$rqCookieMod = $queryArray['__rq_c'] ?? [];
unset($queryArray['__rq_c']);

# --------------- Response Headers Params ---------------------

// [custom_param] response headers

$responseHeadersMod = $queryArray['__rs_h'] ?? [];
unset($queryArray['__rs_h']);

# Response Header Shortcuts

// [custom_param] access-control-allow-headers

$rsAllowHeadersMod = $queryArray['__rs_ah'] ?? 'Origin,Content-Type,X-Requested-With,X-Socket-Id,Sec-Fetch-Mode';
unset($queryArray['__rs_ah']);

// [custom_param] access-control-allow-methods

$rsAllowMethodsMod = $queryArray['__rs_am'] ?? 'GET, POST, PATCH, PUT, DELETE, OPTIONS';
unset($queryArray['__rs_am']);

// [custom_param] access-control-allow-origin

$rsAllowOriginMod = $queryArray['__rs_ao'] ?? '*';
unset($queryArray['__rs_ao']);

# --------------- Response Processing Params -------------------

// [custom_param] response grep

$responseGrep = $queryArray['__rs_g'] ?? null;
unset($queryArray['__rs_g']);

// [custom_param] response grep

$responseFormat = $queryArray['__rs_f'] ?? 'json';
unset($queryArray['__rs_f']);

# --------------- Processing -------------------

// Convert all keys in $responseHeaders to lowercase
$responseHeadersMod = array_change_key_case($responseHeadersMod);
// preserve original keys of request headers
$requestHeadersModOriginalCasing = ['host' => 'Host'];
foreach ($requestHeadersMod as $key => $value) {
    $requestHeadersModOriginalCasing[strtolower($key)] = $key;
}
// Convert all keys in $requestHeaders to lowercase
$requestHeadersMod = array_change_key_case($requestHeadersMod);
// craft full url with protocol
$url = $urlProtocol . '://' . $url;

function make_request($url, $queryParams, $headers = []): ?array
{
    global $responseHeadersMod;

    $parsedUrl = parse_url($url);

    if (!empty($queryParams)) {
        $query = http_build_query($queryParams);
        $url .= (isset($parsedUrl['query']) ? '&' : '?') . $query;
    }

    $curlRequestHeaders = [];
    foreach ($headers as $key => $value) {
        $curlRequestHeaders[] = $key . ': ' . $value;
    }

    $curlResponseHeaders = [];

    $ch = curl_init();

    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $curlRequestHeaders,
        CURLOPT_RETURNTRANSFER => true,
        # CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$curlResponseHeaders) {
            return headerFunction($headerLine, $curlResponseHeaders);
        }
    ));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $protocol = curl_getinfo($ch, CURLINFO_PROTOCOL);

    $contentEncoding = trim($curlResponseHeaders['content-encoding'] ?? 'none');

    foreach ($responseHeadersMod as $key => $value) {
        $keyLower = trim(strtolower($key));
        if (!isset($curlResponseHeaders[$keyLower])) {
            $curlResponseHeaders[$keyLower] = trim($value);
            header($keyLower . ': ' . trim($value));
        }
    }

    if ($contentEncoding === 'gzip') {
        $response = gzdecode($response);
    }

    return [$protocol, $status, $curlResponseHeaders, $response];
}

# --------------- Response Headers Processing -------------------

// Custom header function to add or override response headers
function headerFunction($headerLine, &$curlResponseHeaders): int
{
    global $responseHeadersMod;
    global $rsAllowHeadersMod;
    global $rsAllowMethodsMod;
    global $rsAllowOriginMod;

    // Split the header line into key and value
    $parts = explode(':', $headerLine, 2);

    if (count($parts) == 2) {
        $key = trim(strtolower($parts[0])); // Convert key to lowercase
        $value = trim($parts[1]);

        // Check if the header key exists in $responseHeaders
        if (array_key_exists($key, $responseHeadersMod)) {
            // Override the header value
            $value = $responseHeadersMod[$key];
        } elseif ($key === 'access-control-allow-headers') {
            $value = mergeHeaderValueList($value, $rsAllowHeadersMod);
        } elseif ($key === 'access-control-allow-methods') {
            $value = mergeHeaderValueList($value, $rsAllowMethodsMod);
        } elseif ($key === 'access-control-allow-origin') {
            $value = mergeHeaderValueList($value, $rsAllowOriginMod);
        }

        $curlResponseHeaders[$key] = $value;

        // Output the updated header (preserve the original casing of the key)
        // extra: forbid changing content-encoding and content-type headers
        if ($key !== 'content-encoding' && $key !== 'content-type') {
            header($parts[0] . ': ' . $value);
        }
    }

    return strlen($headerLine);
}

function mergeHeaderValueList($values, $customValues): string
{
    $receivedValues = array_map('trim', explode(',', $values));
    $customHeaderValues = array_map('trim', explode(',', $customValues));
    $mergedAllowHeaders = array_unique(array_merge($receivedValues, $customHeaderValues));

    return implode(', ', $mergedAllowHeaders);
}

function generateRequestHeaders(): array
{
    global $url;
    global $requestHeadersMod;
    global $requestHeadersModOriginalCasing;
    global $rqCookieMod;

    // Get all request headers
    $originalHeaders = getallheaders();

    // override host
    $requestHeadersMod['host'] = parse_url($url)['host'];

    // Convert all keys in $originalHeaders to lowercase and store the original casing
    $headers = [];
    $originalCasing = [];
    foreach ($originalHeaders as $key => $value) {
        $lowerKey = strtolower($key);
        $headers[$lowerKey] = $value;
        $originalCasing[$lowerKey] = $key;
    }

    // Override request headers from $requestHeaders array
    foreach ($requestHeadersMod as $key => $value) {
        $headers[$key] = $value;
        $originalCasing[$key] = $requestHeadersModOriginalCasing[$key];
    }

    // Process the cookies
    $cookies = $headers['cookie'] ?? '';

    if (!empty($rqCookieMod)) {
        foreach ($rqCookieMod as $cookieKey => $cookieValue) {
            $cookies .= '; ' . $cookieKey . '=' . $cookieValue;
        }
        $headers['cookie'] = $cookies;
    }

    // Construct cURL headers using the original casing of the keys
    $curlHeaders = [];
    foreach ($headers as $key => $value) {
        $curlHeaders[$originalCasing[$key]] = $value;
    }

    return $curlHeaders;
}

$requestHeaders = generateRequestHeaders();

list($rsProtocol, $rsHttpCode, $rsHeaders, $rsBody) = make_request($url, $queryArray, $requestHeaders);

# --------------- Response Processing -------------------

# grep

if ($responseGrep !== null) {
    $grepResponse = [];
    $lines = explode("\n", $rsBody);
    $grepPattern = '/' . preg_quote($responseGrep, '/') . '/';

    foreach ($lines as $lineNumber => $line) {
        if (preg_match($grepPattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
            $start = max(0, (int)$matches[0][1] - 25);
            $end = min(strlen($line), (int)$matches[0][1] + strlen($responseGrep) + 25);
            $excerpt = substr($line, $start, $end - $start);

            if ($start > 0) {
                $excerpt = '... ' . $excerpt;
            }

            if ($end < strlen($line)) {
                $excerpt .= ' ...';
            }

            $grepResponse[$lineNumber + 1] = $excerpt;
        }
    }

    $result = ["grep_response" => $grepResponse];
} else {
    $result = [
        "protocol" => $rsProtocol,
        "http_code" => $rsHttpCode,
        "headers" => $rsHeaders,
        "content" => $rsBody
    ];
}

# --------------- Output -------------------

if ($responseFormat === 'json') {
    header('Content-Type: application/json');
    echo json_encode($result);
} else {
    header('Content-Type: text/html');
    $result['content'] = htmlentities($result['content']);
    echo "<pre>";
    echo print_r($result, true);
    echo "</pre>";
}
