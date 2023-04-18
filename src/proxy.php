<?php

// create array of query params

$queryArray = [];
parse_str($_SERVER['QUERY_STRING'], $queryArray);

// get target url

$url = $queryArray['url'];
unset($queryArray['url']);

// [custom_param] request url protocol

$protocol = $queryArray['__rq_p'] ?? 'https';
unset($queryArray['__rq_p']);

# --------------- Request Headers Params ---------------------

// [custom_param] request headers

$requestHeaders = $queryArray['__rq_h'] ?? [];
unset($queryArray['__rq_h']);

# Request Header Shortcuts

$rqCookie = $queryArray['__rq_c'] ?? [];
unset($queryArray['__rq_c']);

# --------------- Response Headers Params ---------------------

// [custom_param] response headers

$responseHeaders = $queryArray['__rs_h'] ?? [];
unset($queryArray['__rs_h']);

# Response Header Shortcuts

// [custom_param] access-control-allow-headers

$rsAllowHeaders = $queryArray['__rs_ah'] ?? 'Origin,Content-Type,X-Requested-With,X-Socket-Id,Sec-Fetch-Mode';
unset($queryArray['__rs_ah']);

// [custom_param] access-control-allow-methods

$rsAllowMethods = $queryArray['__rs_am'] ?? 'GET, POST, PATCH, PUT, DELETE, OPTIONS';
unset($queryArray['__rs_am']);

// [custom_param] access-control-allow-origin

$rsAllowOrigin = $queryArray['__rs_ao'] ?? '*';
unset($queryArray['__rs_ao']);

# --------------- Response Processing Params -------------------

// [custom_param] response grep

$grep = $queryArray['__rs_g'] ?? null;
unset($queryArray['__rs_g']);

# --------------- Processing -------------------

// Convert all keys in $responseHeaders to lowercase
$responseHeaders = array_change_key_case($responseHeaders);
// preserve original keys of request headers
$requestHeadersOriginalCasing = ['host' => 'Host'];
foreach ($requestHeaders as $key => $value) {
    $requestHeadersOriginalCasing[strtolower($key)] = $key;
}
// Convert all keys in $requestHeaders to lowercase
$requestHeaders = array_change_key_case($requestHeaders);
// craft full url with protocol
$url = $protocol . '://' . $url;

function make_request($url, $queryParams, $headers = []): bool|string|null
{
    $parsedUrl = parse_url($url);

    if (!empty($queryParams)) {
        $query = http_build_query($queryParams);
        $url .= (isset($parsedUrl['query']) ? '&' : '?') . $query;
    }

    $curlHeaders = [];
    foreach ($headers as $key => $value) {
        $curlHeaders[] = $key . ': ' . $value;
    }

    $ch = curl_init();

    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_RETURNTRANSFER => true,
        # CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) {
            return headerFunction($headerLine);
        }
    ));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return null;
    }

    return $response;
}

# --------------- Response Headers Processing -------------------

// Custom header function to add or override response headers
function headerFunction($headerLine): int
{
    global $responseHeaders;
    global $rsAllowHeaders;
    global $rsAllowMethods;
    global $rsAllowOrigin;

    // Split the header line into key and value
    $parts = explode(':', $headerLine, 2);
    if (count($parts) == 2) {
        $key = trim(strtolower($parts[0])); // Convert key to lowercase
        $value = trim($parts[1]);

        // Check if the header key exists in $responseHeaders
        if (array_key_exists($key, $responseHeaders)) {
            // Override the header value
            $value = $responseHeaders[$key];
        } elseif ($key === 'access-control-allow-headers') {
            $value = mergeHeaderValueList($headerLine, $rsAllowHeaders);
        } elseif ($key === 'access-control-allow-methods') {
            $value = mergeHeaderValueList($headerLine, $rsAllowMethods);
        } elseif ($key === 'access-control-allow-origin') {
            $value = mergeHeaderValueList($headerLine, $rsAllowOrigin);
        }

        // Output the updated header (preserve the original casing of the key)
        // extra: forbid changing content-encoding and content-type headers
        if ($key !== 'content-encoding' && $key !== 'content-type') {
            header($parts[0] . ': ' . $value);
        }
    }

    return strlen($headerLine);
}

function mergeHeaderValueList($header, $customValues): string
{
    $headerName = explode(':', $header, 2)[0];
    $headerValues = trim(explode(':', $header, 2)[1]);
    $receivedAllowHeaders = explode(', ', $headerValues);
    $mergedAllowHeaders = array_unique(array_merge($receivedAllowHeaders, explode(',', $customValues)));

    return $headerName . ': ' . implode(', ', $mergedAllowHeaders);
}

# --------------- Request Headers Processing -------------------

// Get all request headers
$originalHeaders = getallheaders();

// override host
$requestHeaders['host'] = parse_url($url)['host'];

// Convert all keys in $originalHeaders to lowercase and store the original casing
$headers = [];
$originalCasing = [];
foreach ($originalHeaders as $key => $value) {
    $lowerKey = strtolower($key);
    $headers[$lowerKey] = $value;
    $originalCasing[$lowerKey] = $key;
}

// Override request headers from $requestHeaders array
foreach ($requestHeaders as $key => $value) {
    $headers[$key] = $value;
    $originalCasing[$key] = $requestHeadersOriginalCasing[$key];
}

// Process the cookies
$cookies = $headers['cookie'] ?? '';

if (!empty($rqCookie)) {
    foreach ($rqCookie as $cookieKey => $cookieValue) {
        $cookies .= '; ' . $cookieKey . '=' . $cookieValue;
    }
    $headers['cookie'] = $cookies;
}

// Construct cURL headers using the original casing of the keys
$curlHeaders = [];
foreach ($headers as $key => $value) {
    $curlHeaders[$originalCasing[$key]] = $value;
}

$response = make_request($url, $queryArray, $curlHeaders);

// Remove non-printable and non-ASCII characters
$response = preg_replace('/[^\x20-\x7E]/', '', $response);

# --------------- Response Processing -------------------

# grep

if ($grep !== null) {
    $grepResponse = [];
    $lines = explode("\n", $response);
    $grepPattern = '/' . preg_quote($grep, '/') . '/';

    foreach ($lines as $lineNumber => $line) {
        if (preg_match($grepPattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
            $start = max(0, (int)$matches[0][1] - 25);
            $end = min(strlen($line), (int)$matches[0][1] + strlen($grep) + 25);
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
    $result = ["response" => $response];
}

# --------------- Output -------------------

header('Content-Type: application/json');
echo json_encode($result);