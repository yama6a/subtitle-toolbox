<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use DOMDocument;
use LibXMLError;

/**
 * Loads XML and HTML with the libxml errors collected, not printed. It restores the libxml error setting of the
 * caller, also when the load throws.
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
