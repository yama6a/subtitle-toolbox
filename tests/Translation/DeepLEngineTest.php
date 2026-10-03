<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\TranslationException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Http\FakeHttpClient;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

require_once __DIR__ . "/../Http/FakeHttpClient.php";

class DeepLEngineTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/translation/";

    private const KEY = "0f6c2a8e-6c1d-4b5e-9f3a-2d7e8b1c4a90";

    private const TEXTS = [
        "The train to Basel leaves from platform 4 at <x1>10:15</x1>.",
        "<x1>Tickets &amp; seat\nreservations</x1> are sold here.",
        "- Is this seat free?\n- Yes, it is.",
        "<x1>The next stop is Zurich main station.</x1>",
    ];


    private static function recorded(): FakeHttpClient
    {
        return new FakeHttpClient([[200, file_get_contents(self::FILES . "deepl_de.json")]]);
    }


    public function testSendsTheKeyTagHandlingAndLanguageCodes(): void
    {
        $client = self::recorded();

        $translations = (new DeepLEngine(self::KEY, null, $client))->translate(self::TEXTS, "en", "de");

        $this->assertSame([[
            "url"     => "https://api.deepl.com/v2/translate",
            "headers" => ["Authorization: DeepL-Auth-Key " . self::KEY, "Content-Type: application/json"],
            "body"    => ["text" => self::TEXTS, "target_lang" => "DE", "tag_handling" => "xml", "source_lang" => "EN"],
        ]], $client->requests);
        $this->assertSame([
            "Der Zug nach Basel fährt um <x1>10:15</x1> von Gleis 4 ab.",
            "<x1>Fahrkarten &amp; Platz-\nreservierungen</x1> gibt es hier.",
            "- Ist dieser Platz frei?\n- Ja, ist er.",
            "<x1>Der nächste Halt ist Zürich Hauptbahnhof.</x1>",
        ], $translations);
    }


    public function testAFreeKeyGoesToTheFreeHostAndABaseUrlWins(): void
    {
        $client = new FakeHttpClient([[200, '{"translations": [{"text": "Hallo"}]}'], [200, '{"translations": [{"text": "Hallo"}]}']]);

        (new DeepLEngine("abc:fx", null, $client))->translate(["Hello"], "en", "de");
        (new DeepLEngine("abc:fx", "http://127.0.0.1:8080/", $client))->translate(["Hello"], "en", "de");

        $this->assertSame(["https://api-free.deepl.com/v2/translate", "http://127.0.0.1:8080/v2/translate"], array_column($client->requests, "url"));
    }


    public function testWithoutASourceLanguageDeepLDetectsIt(): void
    {
        $client = new FakeHttpClient([[200, '{"translations": [{"detected_source_language": "DE", "text": "Run!"}]}']]);

        $this->assertSame(["Run!"], (new DeepLEngine(self::KEY, null, $client))->translate(["Lauf!"], "", "en-US"));
        $this->assertSame(["text" => ["Lauf!"], "target_lang" => "EN-US", "tag_handling" => "xml"], $client->requests[0]["body"]);
    }


    public function testSplits120TextsIntoRequestsOfAtMost50AndKeepsTheOrder(): void
    {
        $texts  = array_map(fn (int $number): string => "text $number", range(1, 120));
        $client = new FakeHttpClient(respond: fn (array $body): array => [200, json_encode(["translations" => array_map(
            fn (string $text): array => ["text" => strtoupper($text)],
            $body["text"]
        )])]);

        $translations = (new DeepLEngine(self::KEY, null, $client))->translate($texts, "en", "de");

        $this->assertSame([50, 50, 20], array_map(fn (array $request): int => count($request["body"]["text"]), $client->requests));
        $this->assertSame(array_map("strtoupper", $texts), $translations);
        $this->assertSame([], (new DeepLEngine(self::KEY, null, $client))->translate([], "en", "de"));
        $this->assertCount(3, $client->requests);
    }


    /**
     * @return array<string, array{int, string, string}>
     */
    public static function errors(): array
    {
        return [
            "403 wrong key"   => [403, '{"message": "Forbidden"}', "DeepL rejected the API key (HTTP 403). Check the key and its plan."],
            "429 busy"        => [429, '{"message": "Too many requests"}', "DeepL got too many requests (HTTP 429). Wait and try again."],
            "456 quota"       => [456, '{"message": "Quota exceeded"}', "The DeepL character quota is used up (HTTP 456)."],
            "400 recorded"    => [400, file_get_contents(self::FILES . "deepl_error.json"),
                                  "DeepL answered with HTTP 400. Value for 'target_lang' not supported."],
            "500 key in body" => [500, '{"message": "Unknown key ' . self::KEY . '"}', "DeepL answered with HTTP 500. Unknown key ***"],
        ];
    }


    #[DataProvider("errors")]
    public function testAnErrorStatusNamesTheCauseWithoutTheKey(int $status, string $body, string $message): void
    {
        try {
            (new DeepLEngine(self::KEY, null, new FakeHttpClient([[$status, $body]])))->translate(["Hello"], "en", "de");
            $this->fail("No exception");
        } catch (TranslationException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
    }


    public function testAnAnswerWithTooFewTranslationsFails(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage("DeepL returned 1 translations for 2 texts, or an answer that is not the JSON of /v2/translate.");

        (new DeepLEngine(self::KEY, null, new FakeHttpClient([[200, '{"translations": [{"text": "Hallo"}]}']])))->translate(["Hello", "Bye"], "en", "de");
    }


    public function testTheRunnerKeepsTheTagsAndTheCueTimesOfARealFile(): void
    {
        $original = Subtitle::load(self::FILES . "own_station.srt", Format::SubRip);

        $translated = (new TranslationRunner(new DeepLEngine(self::KEY, null, self::recorded())))->translate($original, "en", "de");

        $this->assertSame([
            ["Der Zug nach Basel fährt"],
            ["um <b>10:15</b> von Gleis 4 ab."],
            ["\u{266A} \u{266A}"],
            ["<i>Fahrkarten &amp; Platz-", "reservierungen</i> gibt es hier."],
            ["2024"],
            ["- Ist dieser Platz frei?", "- Ja, ist er."],
            ["<i>Der nächste Halt ist</i>"],
            ["<i>Zürich Hauptbahnhof.</i>"],
        ], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $translated->getCues()));
        $this->assertSame(
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $original->getCues()),
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $translated->getCues())
        );
    }
}
