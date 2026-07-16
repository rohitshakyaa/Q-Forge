<?php

namespace App\Services\Rag;

/**
 * Splits markdown course material into retrievable chunks (M6 Phase 2 —
 * docs/RAG-GUIDE.md §5).
 *
 * The tension: too big and the vector is a blurry average (dilution), too
 * small and "It uses a three-way handshake" loses its subject (context loss).
 * Mitigations, in order:
 *  - split on markdown headings — natural topic boundaries, free in this corpus
 *    (unit content and syllabi are markdown by construction since M4.1);
 *  - oversized sections fall back to paragraph packing;
 *  - undersized sections merge forward into their neighbour;
 *  - every chunk records the heading path it came from, which the indexer
 *    prefixes into the embedding so short chunks keep their context.
 *
 * Sizes are in characters (~4 chars/token for English): target ~1600 ≈ 400
 * tokens, floor ~200 ≈ 50 tokens.
 */
class ContentChunker
{
    public const TARGET_CHARS = 1600;

    public const MIN_CHARS = 200;

    /**
     * @param  string  $markdown  the source document
     * @param  string  $contextPath  where it lives ("CS301 > Unit 3: Transport Layer")
     * @return array<int, array{heading: string, text: string}>  in reading order
     */
    public function chunk(string $markdown, string $contextPath): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return [];
        }

        $chunks = [];
        foreach ($this->splitByHeadings($markdown) as $section) {
            $heading = $section['heading'] !== ''
                ? "{$contextPath} > {$section['heading']}"
                : $contextPath;

            foreach ($this->packParagraphs($section['body']) as $piece) {
                $chunks[] = ['heading' => $heading, 'text' => $piece];
            }
        }

        return $this->mergeRunts($chunks);
    }

    /**
     * Split on markdown ATX headings (#, ##, ...), keeping each heading with the
     * body below it. Text before the first heading becomes a heading-less section.
     *
     * @return array<int, array{heading: string, body: string}>
     */
    private function splitByHeadings(string $markdown): array
    {
        $sections = [];
        $heading = '';
        $body = [];

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m)) {
                if (trim(implode("\n", $body)) !== '') {
                    $sections[] = ['heading' => $heading, 'body' => trim(implode("\n", $body))];
                }
                $heading = trim($m[1]);
                $body = [];

                continue;
            }
            $body[] = $line;
        }

        if (trim(implode("\n", $body)) !== '') {
            $sections[] = ['heading' => $heading, 'body' => trim(implode("\n", $body))];
        }

        return $sections;
    }

    /**
     * Pack a section's paragraphs into pieces of roughly TARGET_CHARS, never
     * splitting inside a paragraph (a paragraph is the smallest unit of meaning
     * we're willing to sever).
     *
     * @return string[]
     */
    private function packParagraphs(string $body): array
    {
        $paragraphs = preg_split('/\n{2,}/', $body) ?: [];

        $pieces = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $joined = $current === '' ? $paragraph : "{$current}\n\n{$paragraph}";
            if ($current !== '' && mb_strlen($joined) > self::TARGET_CHARS) {
                $pieces[] = $current;
                $current = $paragraph;
            } else {
                $current = $joined;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * Merge sub-MIN_CHARS chunks into the previous chunk of the same heading
     * (or the next one, for a runt at the start) so near-empty vectors — which
     * match everything weakly and nothing well — never reach the index.
     *
     * @param  array<int, array{heading: string, text: string}>  $chunks
     * @return array<int, array{heading: string, text: string}>
     */
    private function mergeRunts(array $chunks): array
    {
        $merged = [];

        foreach ($chunks as $chunk) {
            $last = count($merged) - 1;
            $isRunt = mb_strlen($chunk['text']) < self::MIN_CHARS;

            if ($isRunt && $last >= 0 && $merged[$last]['heading'] === $chunk['heading']) {
                $merged[$last]['text'] .= "\n\n{$chunk['text']}";
            } else {
                $merged[] = $chunk;
            }
        }

        // A runt at a heading boundary (nothing before it to join): fold it into
        // the following chunk as a prefix instead, if one exists.
        for ($i = 0; $i < count($merged) - 1; $i++) {
            if (mb_strlen($merged[$i]['text']) < self::MIN_CHARS) {
                $merged[$i + 1]['text'] = "{$merged[$i]['text']}\n\n{$merged[$i + 1]['text']}";
                array_splice($merged, $i, 1);
                $i--;
            }
        }

        return $merged;
    }
}
