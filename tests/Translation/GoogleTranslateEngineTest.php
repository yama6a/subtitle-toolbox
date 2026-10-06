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

class GoogleTranslateEngineTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/translation/";

    private const KEY = "AIzaSyD-example+key/42";

    private const TEXTS = [
        "The train to Basel leaves from platform 4 at <x1>10:15</x1>.",
        "<x1>Tickets &amp; seat\nreservations</x1> are sold here.",
        "- Is this seat free?\n- Yes, it is.",
        "<x1>The next stop is Zurich main station.</x1>",
    ];


    private static function recorded(): FakeHttpClient
    {
        return new FakeHttpClient([[200, file_get_contents(self::FILES . "google_fr.json")]]);
    }


    public function testSendsTheKeyAsAQueryParameterAndFormatHtml(): void
    {
        $client = self::recorded();

        $translations = (new GoogleTranslateEngine(new GoogleTranslateOptions(self::KEY, httpClient: $client)))->translate(self::TEXTS, "en", "fr");

        $this->assertSame([[
            "url"     => "https://translation.googleapis.com/language/translate/v2",
            "headers" => ["X-goog-api-key: " . self::KEY, "Content-Type: application/json"],
            "body"    => ["q" => self::TEXTS, "target" => "fr", "format" => "html", "source" => "en"],
        ]], $client->requests);
        $this->assertSame([
            "Le train pour Bâle part du quai 4 à <x1>10:15</x1>.",
            "<x1>Les billets &amp; les réservations\nde places</x1> sont vendus ici.",
            "- Cette place est-elle libre ?\n- Oui, c&#39;est libre.",
            "<x1>Le prochain arrêt est la gare centrale de Zurich.</x1>",
        ], $translations);
    }


    public function testReturnsTheAnswerAsReceivedForTheRunnerToDecode(): void
    {
        $client = new FakeHttpClient([[200, '{"data": {"translations": [{"translatedText": "l&#39;a &amp; &quot;b&quot; &lt;x1&gt; &#60; &eacute;"}]}}']]);

        $this->assertSame(["l&#39;a &amp; &quot;b&quot; &lt;x1&gt; &#60; &eacute;"], (new GoogleTranslateEngine(new GoogleTranslateOptions(self::KEY, httpClient: $client)))->translate(["x"], "en", "fr"));
    }


    public function testWithoutASourceLanguageGoogleDetectsItAndABaseUrlWins(): void
    {
        $client = new FakeHttpClient([[200, '{"data": {"translations": [{"translatedText": "Cours !", "detectedSourceLanguage": "de"}]}}']]);

        $translations = (new GoogleTranslateEngine(new GoogleTranslateOptions("key", "http://127.0.0.1:8080/", $client)))->translate(["Lauf!"], "", "fr");

        $this->assertSame(["Cours !"], $translations);
        $this->assertSame("http://127.0.0.1:8080/language/translate/v2", $client->requests[0]["url"]);
        $this->assertSame(["q" => ["Lauf!"], "target" => "fr", "format" => "html"], $client->requests[0]["body"]);
    }


    public function testSplitsTextsIntoRequestsOfAtMost128AndKeepsTheOrder(): void
    {
        $texts  = array_map(fn (int $number): string => "text $number", range(1, 300));
        $client = new FakeHttpClient(respond: fn (array $body): array => [200, json_encode(["data" => ["translations" => array_map(
            fn (string $text): array => ["translatedText" => strtoupper($text)],
            $body["q"]
        )]])]);

        $translations = (new GoogleTranslateEngine(new GoogleTranslateOptions(self::KEY, httpClient: $client)))->translate($texts, "en", "fr");

        $this->assertSame([128, 128, 44], array_map(fn (array $request): int => count($request["body"]["q"]), $client->requests));
        $this->assertSame(array_map("strtoupper", $texts), $translations);
    }


    /**
     * @return array<string, array{int, string, string}>
     */
    public static function errors(): array
    {
        return [
            "403 not enabled" => [403, '{"error": {"code": 403, "message": "Cloud Translation API has not been used in project 1."}}',
                                  "Google Cloud Translation refused the request (HTTP 403). Check the key and that the API is enabled. " .
                                  "Cloud Translation API has not been used in project 1."],
            "429 busy"        => [429, '{"error": {"code": 429, "message": "Quota exceeded"}}',
                                  "Google Cloud Translation got too many requests (HTTP 429). Wait and try again."],
            "400 recorded"    => [400, file_get_contents(self::FILES . "google_error.json"),
                                  "Google Cloud Translation answered with HTTP 400. API key not valid. Please pass a valid API key."],
            "400 key in body" => [400, '{"error": {"message": "Bad key ' . self::KEY . '"}}',
                                  "Google Cloud Translation answered with HTTP 400. Bad key ***"],
        ];
    }


    #[DataProvider("errors")]
    public function testAnErrorStatusNamesTheCauseWithoutTheKey(int $status, string $body, string $message): void
    {
        try {
            (new GoogleTranslateEngine(new GoogleTranslateOptions(self::KEY, httpClient: new FakeHttpClient([[$status, $body]]))))->translate(["Hello"], "en", "fr");
            $this->fail("No exception");
        } catch (TranslationException $exception) {
            $this->assertSame("TranslationException (Error #109): $message", $exception->getMessage());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
    }


    public function testAnAnswerThatIsNotJsonFails(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage("TranslationException (Error #109): Google Cloud Translation returned 0 translations for 1 texts, or an answer that is not the JSON of translate v2.");

        (new GoogleTranslateEngine(new GoogleTranslateOptions(self::KEY, httpClient: new FakeHttpClient([[200, "<html>"]]))))->translate(["Hello"], "en", "fr");
    }


    public function testTheRunnerKeepsTheTagsAndTheCueTimesOfARealFile(): void
    {
        $original = Subtitle::load(self::FILES . "own_station.srt", Format::SubRip);

        $translated = clone $original;
        $report     = (new TranslationRunner(new GoogleTranslateEngine(new GoogleTranslateOptions(self::KEY, httpClient: self::recorded()))))->translate($translated, "en", "fr");

        $this->assertSame([
            ["Le train pour Bâle part"],
            ["du quai 4 à <b>10:15</b>."],
            ["\u{266A} \u{266A}"],
            ["<i>Les billets &amp; les réservations", "de places</i> sont vendus ici."],
            ["2024"],
            ["- Cette place est-elle libre ?", "- Oui, c'est libre."],
            ["<i>Le prochain arrêt est</i>"],
            ["<i>la gare centrale de Zurich.</i>"],
        ], array_map(fn (SubtitleCue $cue): array => $cue->getLines(), $translated->getCues()));
        $this->assertSame([], $report->warnings);
        $this->assertSame(Subtitle::load(self::FILES . "own_station.srt", Format::SubRip)->toString(Format::SubRip), $original->toString(Format::SubRip));
        $this->assertSame(
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $original->getCues()),
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $translated->getCues())
        );
    }


    public function testTheRunnerDecodesTheEntitiesOfTheAnswerOnce(): void
    {
        $echo     = new FakeHttpClient(respond: fn (array $body): array => [200, json_encode(["data" => ["translations" => array_map(
            fn (string $text): array => ["translatedText" => $text],
            $body["q"]
        )]])]);
        $original = Subtitle::load(self::FILES . "own_entities.srt", Format::SubRip);
        $copy     = clone $original;

        (new TranslationRunner(new GoogleTranslateEngine(new GoogleTranslateOptions(self::KEY, httpClient: $echo))))->translate($copy, "en", "fr");

        $this->assertSame(["Write &amp;lt;i&amp;gt;Run&amp;lt;/i&amp;gt; for italics.", "Tom &amp;amp; Jerry is a cat and mouse act."],
                          $echo->requests[0]["body"]["q"]);
        $this->assertSame($original->toString(Format::SubRip), $copy->toString(Format::SubRip));
        $this->assertStringContainsString("Write &lt;i&gt;Run&lt;/i&gt; for italics.", $copy->toString(Format::SubRip));
    }
}
