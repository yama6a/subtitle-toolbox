<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * The XML namespaces of TTML, DFXP and iTT that TtmlParser, TtmlFormatter and IttFormatter share.
 *
 * @internal
 */
final class TtmlNamespaces
{
    public const TTML = "http://www.w3.org/ns/ttml";
    public const DFXP = "http://www.w3.org/2006/10/ttaf1";
    public const XML  = "http://www.w3.org/XML/1998/namespace";

    public const IMSC_STYLING = "http://www.w3.org/ns/ttml/profile/imsc1#styling";

    public const STYLING   = [
        "http://www.w3.org/ns/ttml#styling",
        "http://www.w3.org/2006/10/ttaf1#style",
        "http://www.w3.org/2006/10/ttaf1#styling",
    ];
    public const PARAMETER = ["http://www.w3.org/ns/ttml#parameter", "http://www.w3.org/2006/10/ttaf1#parameter"];
    public const METADATA  = ["http://www.w3.org/ns/ttml#metadata", "http://www.w3.org/2006/10/ttaf1#metadata"];
}
