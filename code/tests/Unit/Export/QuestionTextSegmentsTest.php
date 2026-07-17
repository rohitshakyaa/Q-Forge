<?php

namespace Tests\Unit\Export;

use App\Services\Export\QuestionTextSegments;
use PHPUnit\Framework\TestCase;

class QuestionTextSegmentsTest extends TestCase
{
    public function test_plain_text_is_one_prose_segment(): void
    {
        $segments = QuestionTextSegments::parse("Explain BFS\nand DFS.");

        $this->assertSame([['kind' => 'prose', 'text' => "Explain BFS\nand DFS."]], $segments);
    }

    public function test_markdown_table_becomes_a_table_segment_between_prose(): void
    {
        $text = implode("\n", [
            'Perform Earned Value Analysis of the project.',
            '| Activity | Duration | Precedence | Cost/day |',
            '|---|---|---|---|',
            '| A | 5 | | 200 |',
            '| B | 5 | A | 200 |',
            'Calculate SV, SPI, CV and CPI respectively.',
        ]);

        $segments = QuestionTextSegments::parse($text);

        $this->assertCount(3, $segments);
        $this->assertSame('prose', $segments[0]['kind']);
        $this->assertSame('table', $segments[1]['kind']);
        $this->assertSame('prose', $segments[2]['kind']);

        // The separator row is layout, not data; empty cells survive as ''.
        $this->assertSame([
            ['Activity', 'Duration', 'Precedence', 'Cost/day'],
            ['A', '5', '', '200'],
            ['B', '5', 'A', '200'],
        ], $segments[1]['rows']);
    }

    public function test_a_question_that_is_only_a_table_parses(): void
    {
        $segments = QuestionTextSegments::parse("| Year | Project |\n|---|---|\n| 0 | -50000 |");

        $this->assertCount(1, $segments);
        $this->assertSame('table', $segments[0]['kind']);
        $this->assertSame([['Year', 'Project'], ['0', '-50000']], $segments[0]['rows']);
    }
}
