<?php

class ApiService {
    public function request($method, $url, $headers = [], $params = []) {
        $ch = curl_init();

        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            $formattedHeaders[] = "$key: $value";
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 关闭SSL证书验证

        if (!empty($params)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
        }

        curl_close($ch);

        if (isset($error_msg)) {
            die("Curl error: $error_msg");
        }

        return [
            'statusCode' => $statusCode,
            'raw' => $response
        ];
    }
}

function createDirectoryStructure($host) {
    $directories = ["$host/css", "$host/js", "$host/images"];
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

function normalizeUrl($url, $baseUrl) {
    if (strpos($url, '//') === 0) {
        return 'http:' . $url;
    }
    if (strpos($url, 'http') === 0) {
        return $url;
    }
    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

function fetchHtml($api, $url) {
    $headers = getDefaultHeaders();
    $res = $api->request('get', $url, $headers);
    if ((int)$res['statusCode'] !== 200) {
        die('获取html异常');
    }
    return $res['raw'];
}

function getDefaultHeaders() {
    return [
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8,zh-TW;q=0.7,ja;q=0.6',
        'Cache-Control' => 'max-age=0',
        'Connection' => 'keep-alive',
        'Upgrade-Insecure-Requests' => '1',
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.99 Safari/537.36',
    ];
}

function processHtml($api, $html, $host, $baseUrl) {
    $html = processResources($api, $html, $host, $baseUrl, 'href', 'css');
    $html = processResources($api, $html, $host, $baseUrl, 'src', 'js');
    $html = processResources($api, $html, $host, $baseUrl, 'src', 'images');
    return $html;
}

function processResources($api, $html, $host, $baseUrl, $attribute, $type) {
    preg_match_all("#$attribute=\"(.*?)\"#is", $html, $matches);
    foreach ($matches[1] as $url) {
        if (($type === 'css' && strpos($url, '.css') !== false) ||
            ($type === 'js' && strpos($url, '.js') !== false) ||
            ($type === 'images' && preg_match('/\.(gif|jpg|jpeg|png)$/', $url))) {
            $html = replaceResource($api, $html, $url, $type, $host, $baseUrl, $attribute);
        }
    }
    return $html;
}

function replaceResource($api, $html, $url, $type, $host, $baseUrl, $attribute) {
    $originalUrl = $url;
    $url = normalizeUrl($url, $baseUrl);
    $filePath = downloadResource($api, $url, $type, $host);
    $relativePath = "./$type/" . basename($filePath);
    return str_replace("$attribute=\"$originalUrl\"", "$attribute=\"$relativePath\"", $html);
}

function downloadResource($api, $url, $type, $host) {
    $headers = getDefaultHeaders();
    $res = $api->request('get', $url, $headers);
    if ((int)$res['statusCode'] !== 200) {
        die("获取 $url 异常");
    }
    $fileName = basename($url);
    $filePath = "$host/$type/$fileName";
    file_put_contents($filePath, $res['raw']);
    return $filePath;
}

$url = $_POST['url'] ?? null;
$name = $_POST['filename'] ?? null;

if (empty($url) || empty($name)) {
    die('参数错误, 请返回并确保所有字段都已填写');
}

$api = new ApiService();
$wwwInfo = parse_url($url);
$scheme = $wwwInfo['scheme'] . '://';
$host = $wwwInfo['host'];

// 创建默认文件夹
createDirectoryStructure($host);

// 获取网页内容
$html = fetchHtml($api, $url);
file_put_contents("$host/$name", $html);

// 处理 HTML 内容
$html = processHtml($api, $html, $host, $scheme . $host);

// 保存最终内容
file_put_contents("$host/$name", $html);
echo '下载完成，请返回';
?>
