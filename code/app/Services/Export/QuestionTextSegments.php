<?php

namespace App\Services\Export;

/**
 * Splits a question's text into prose and table segments for the exporters.
 *
 * The extractor (python-service pdf.py) renders ruled tables on digital pages
 * as markdown — "| A | 5 | | 200 |" rows under a "|---|" separator — so the
 * question text stays a plain string everywhere (DB, API). PDF and DOCX
 * exports call this to turn those blocks back into real tables; the Vue UI
 * does the same client-side (QFQuestionText.vue).
 */
class QuestionTextSegments
{
    /**
     * @return list<array{kind: 'prose', text: string}|array{kind: 'table', rows: list<list<string>>}>
     */
    public static function parse(string $text): array
    {
        $segments = [];
        $prose = [];
        $rows = [];

        $flushProse = function () use (&$segments, &$prose) {
            if ($prose !== []) {
                $segments[] = ['kind' => 'prose', 'text' => implode("\n", $prose)];
                $prose = [];
            }
        };
        $flushTable = function () use (&$segments, &$rows) {
            if ($rows !== []) {
                $segments[] = ['kind' => 'table', 'rows' => $rows];
                $rows = [];
            }
        };

        foreach (explode("\n", $text) as $line) {
            if (str_starts_with(ltrim($line), '|')) {
                $flushProse();
                if (! self::isSeparator($line)) {
                    $rows[] = self::cells($line);
                }
            } else {
                $flushTable();
                $prose[] = $line;
            }
        }
        $flushProse();
        $flushTable();

        return $segments;
    }

    /** The "|---|---|" row markdown puts under the header — layout, not data. */
    private static function isSeparator(string $line): bool
    {
        $trimmed = trim($line);

        return str_contains($trimmed, '-') && preg_match('/^\|?[\s|:\-]+\|?$/', $trimmed) === 1;
    }

    /** @return list<string> */
    private static function cells(string $line): array
    {
        $inner = preg_replace('/^\||\|$/', '', trim($line));

        return array_map(trim(...), explode('|', $inner));
    }
}
