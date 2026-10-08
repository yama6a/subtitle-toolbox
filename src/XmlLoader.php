<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use DOMDocument;
use LibXMLError;

/**
 * Loads XML and HTML with the libxml errors collected, not printed.
 * It restores the libxml error setting of the caller, also when the load throws.
 *
 * @internal
 */
final class XmlLoader
{
    /**
     * Returns the document, or null when $xml is empty or not well-formed. $error receives the last libxml error.
     */
    public static function xml(string $xml, ?LibXMLError &$error = null): ?DOMDocument
    {
        $xml      = self::declareUtf8($xml);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET blocks network access. Without LIBXML_NOENT and LIBXML_DTDLOAD, libxml loads no external entity.
            $loaded = $xml !== "" && $document->loadXML($xml, LIBXML_NONET);
            $error  = libxml_get_last_error() ?: null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded && $document->documentElement !== null ? $document : null;
    }


    /**
     * Subtitle::fromString() converts UTF-16 and UTF-32 input to UTF-8 but keeps the XML declaration.
     * Some tools also declare UTF-16 for UTF-8 files. libxml rejects both.
     * Raw UTF-16 bytes hold zero bytes, so the pattern leaves them alone.
     */
    private static function declareUtf8(string $xml): string
    {
        return preg_replace(
            '/\A(\xEF\xBB\xBF)?(<\?xml\s[^>]*?\bencoding\s*=\s*)(["\'])(?:utf-?(?:16|32)|ucs-?[24])(?:[bl]e)?\3/i',
            '${1}${2}${3}UTF-8${3}',
            $xml,
            1
        ) ?? $xml;
    }


    public static function html(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }
}
