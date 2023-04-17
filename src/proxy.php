<?php

// create array of query params

$queryArray = [];
parse_str($_SERVER['QUERY_STRING'], $queryArray);

// get target url

$url = $queryArray['url'];
unset($queryArray['url']);

// get request header mod

$requestHeaderMod = $queryArray['__rq_h'] ?? [];
unset($queryArray['__rq_h']);

// get grep

$grep = $queryArray['__rs_g'] ?? null;
unset($queryArray['__rs_g']);

// url protocol

$protocol = $queryArray['__rq_p'] ?? 'https';
unset($queryArray['__rq_p']);

// allow headers
$allowHeaders = $queryArray['__rs_ah'] ?? 'Origin,Content-Type,X-Requested-With,X-Socket-Id,Sec-Fetch-Mode';
unset($queryArray['__rs_ah']);

$url = $protocol . '://' . $url;

function make_request($url, $queryParams, $headers = [], $allowHeaders = []): bool|string|null
{
    $parsedUrl = parse_url($url);

    if (!empty($queryParams)) {
        $query = http_build_query($queryParams);
        $url .= (isset($parsedUrl['query']) ? '&' : '?') . $query;
    }

    $headers['Host'] = $parsedUrl['host'];
    $headers['host'] = $parsedUrl['host'];

    $curlHeaders = [];
    foreach ($headers as $key => $value) {
        $curlHeaders[] = $key . ': ' . $value;
    }


    $ch = curl_init();

    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADERFUNCTION => function ($curl, $header) use ($allowHeaders) {
            $headerName = strtolower(explode(':', $header, 2)[0]);

            if (str_contains($headerName, 'access-control-allow-headers')) {
                $newHeader = mergeHeaderValueList($header, $allowHeaders);
                header(trim($newHeader));
            } else if ($headerName !== 'content-encoding' && $headerName !== 'content-type') {
                header(trim($header));
            }

            return strlen($header);
        }
    ));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return null;
    }

    return $response;
}

function mergeHeaderValueList($header, $customValues): string
{
    $headerName = explode(':', $header, 2)[0];
    $headerValues = trim(explode(':', $header, 2)[1]);
    $receivedAllowHeaders = explode(', ', $headerValues);
    $mergedAllowHeaders = array_unique(array_merge($receivedAllowHeaders, explode(',', $customValues)));

    return $headerName . ': ' . implode(', ', $mergedAllowHeaders);
}


$headers = getallheaders();

// Add new Cookie values to the existing Cookie header
if (isset($requestHeaderMod['Cookie'])) {
    $newCookies = http_build_query($requestHeaderMod['Cookie'], '', '; ');

    if (isset($headers['Cookie'])) {
        $headers['Cookie'] .= '; ' . $newCookies;
    } elseif (isset($headers['COOKIE'])) {
        $headers['COOKIE'] .= '; ' . $newCookies;
    } else {
        $headers['Cookie'] = $newCookies;
    }
}

$response = make_request($url, $queryArray, $headers, $allowHeaders);

// Remove non-printable and non-ASCII characters
$response = preg_replace('/[^\x20-\x7E]/', '', $response);

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

header('Content-Type: application/json');
echo json_encode($result);