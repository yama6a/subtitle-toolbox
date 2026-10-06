<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * Common subtitle encodings. Each value is the iconv name. Pass a string for any other name that iconv accepts.
 */
enum TextEncoding: string
{
    case Utf8        = "UTF-8";
    case Utf16Le     = "UTF-16LE";
    case Utf16Be     = "UTF-16BE";
    case Windows1250 = "Windows-1250";
    case Windows1251 = "Windows-1251";
    case Windows1252 = "Windows-1252";
    case Windows1253 = "Windows-1253";
    case Windows1254 = "Windows-1254";
    case Windows1255 = "Windows-1255";
    case Windows1256 = "Windows-1256";
    case Windows1257 = "Windows-1257";
    case Windows1258 = "Windows-1258";
    case Iso8859_1   = "ISO-8859-1";
    case Iso8859_2   = "ISO-8859-2";
    case Iso8859_3   = "ISO-8859-3";
    case Iso8859_4   = "ISO-8859-4";
    case Iso8859_5   = "ISO-8859-5";
    case Iso8859_6   = "ISO-8859-6";
    case Iso8859_7   = "ISO-8859-7";
    case Iso8859_8   = "ISO-8859-8";
    case Iso8859_9   = "ISO-8859-9";
    case Iso8859_10  = "ISO-8859-10";
    case Iso8859_11  = "ISO-8859-11";
    case Iso8859_13  = "ISO-8859-13";
    case Iso8859_14  = "ISO-8859-14";
    case Iso8859_15  = "ISO-8859-15";
    case Iso8859_16  = "ISO-8859-16";
    case Koi8R       = "KOI8-R";
    case Koi8U       = "KOI8-U";
    case ShiftJis    = "Shift_JIS";
    case EucJp       = "EUC-JP";
    case Gbk         = "GBK";
    case Gb18030     = "GB18030";
    case Big5        = "Big5";
    case EucKr       = "EUC-KR";
    case Cp437       = "CP437";
    case Cp850       = "CP850";
    case Cp866       = "CP866";
    case Cp949       = "CP949";
}
