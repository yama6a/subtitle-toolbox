<?php

namespace SubtitleToolbox\Translation;

interface TranslationEngine
{
    /**
     * Returns one translation per text, in the same order. Texts hold placeholder tags such as <x1>...</x1>, <x2/>
     * and the entities &lt;, &gt; and &amp;, which the translations must keep.
     *
     * @param list<string> $texts
     * @return list<string>
     */
    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array;
}
