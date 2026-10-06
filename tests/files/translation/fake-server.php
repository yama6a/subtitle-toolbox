<?php

declare(strict_types=1);

// Router script for "php -S" that plays DeepL on /v2/translate and Google Cloud Translation on /language/translate/v2.
// The keys "forbidden", "busy" and "quota" give HTTP 403, 429 and 456. "bad-request" gives the recorded error of each service.
// Any other path echoes the method, the headers and the body as JSON, with the status of the query parameter "status".
// With FAKE_SERVER_LOG set, each request appends one JSON line with its path, key and body to that file.

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$body = file_get_contents("php://input");
$key  = match ($path) {
    "/v2/translate"           => preg_replace('/^DeepL-Auth-Key /', "", $_SERVER["HTTP_AUTHORIZATION"] ?? ""),
    "/language/translate/v2"  => $_SERVER["HTTP_X_GOOG_API_KEY"] ?? "",
    default                   => null,
};

if (getenv("FAKE_SERVER_LOG")) {
    file_put_contents(getenv("FAKE_SERVER_LOG"), json_encode(["path" => $path, "key" => $key, "body" => json_decode($body, true)]) . "\n", FILE_APPEND);
}

header("Content-Type: application/json");
if ($key === null) {
    http_response_code((int)($_GET["status"] ?? 200));
    echo json_encode(["method" => $_SERVER["REQUEST_METHOD"], "contentType" => $_SERVER["CONTENT_TYPE"] ?? null, "body" => $body]);

    return;
}

$deepL = $path === "/v2/translate";
$error = ["forbidden" => 403, "busy" => 429, "quota" => 456, "bad-request" => 400][$key] ?? null;
if ($error !== null) {
    http_response_code($error);
    echo $key === "bad-request" ? file_get_contents(__DIR__ . ($deepL ? "/deepl_error.json" : "/google_error.json")) : '{"message": "error"}';

    return;
}

echo file_get_contents(__DIR__ . ($deepL ? "/deepl_de.json" : "/google_fr.json"));
