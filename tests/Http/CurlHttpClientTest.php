<?php

declare(strict_types=1);

namespace SubtitleToolbox\Http;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\TranslationException;

require_once __DIR__ . "/LocalServer.php";

class CurlHttpClientTest extends TestCase
{
    private const ROUTER = __DIR__ . "/../files/translation/fake-server.php";


    #[RequiresPhpExtension("curl")]
    public function testPostsToALocalServerAndReadsTheStatusAndTheBody(): void
    {
        $server = LocalServer::start(self::ROUTER);
        try {
            $client = new CurlHttpClient();

            [$status, $body] = $client->post("$server->url/echo?status=418", ["Content-Type: application/json"], '{"a": "ä"}');
            $this->assertSame(418, $status);
            $this->assertSame(["method" => "POST", "contentType" => "application/json", "body" => '{"a": "ä"}'], json_decode($body, true));

            [$status, $body] = $client->post("$server->url/echo", [], "x");
            $this->assertSame(200, $status);
            $this->assertSame("x", json_decode($body, true)["body"]);
        } finally {
            $server->stop();
        }
    }


    #[RequiresPhpExtension("curl")]
    public function testAFailedConnectionNamesTheHostAndNotTheQuery(): void
    {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $port   = (int)substr(strrchr(stream_socket_get_name($socket, false), ":"), 1);
        fclose($socket);

        try {
            (new CurlHttpClient(connectTimeoutSeconds: 2))->post("http://127.0.0.1:$port/v2?key=secret-key", [], "");
            $this->fail("No exception");
        } catch (TranslationException $exception) {
            $this->assertStringStartsWith("The request to 127.0.0.1 failed: ", $exception->getMessage());
            $this->assertStringNotContainsString("secret-key", $exception->getMessage());
        }
    }


    public function testWithoutTheCurlExtensionTheClientNamesIt(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage("The translate engines need the PHP extension curl. Install ext-curl, for example php8.2-curl.");

        new class extends CurlHttpClient {
            protected function curlLoaded(): bool
            {
                return false;
            }
        };
    }
}
