<?php

namespace App\Services\Export;

use App\Services\PaperGeneration\Support\PaperViewModel;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Renders a paper to a .docx file from the shared PaperViewModel. Mirrors the
 * Blade/PDF template so PDF and DOCX exports are structurally identical.
 */
class PaperWordBuilder
{
    public function __construct(private readonly PaperViewModel $vm)
    {
    }

    /**
     * Build the document and write it to a temp path. Caller is responsible for
     * streaming + deleting the file.
     */
    public function build(): string
    {
        $header = $this->vm->header();
        $word = new PhpWord();
        $word->setDefaultFontName('DejaVu Sans');
        $word->setDefaultFontSize(11);

        $section = $word->addSection([
            'marginLeft' => 1100,
            'marginRight' => 1100,
            'marginTop' => 1000,
            'marginBottom' => 1000,
        ]);

        $center = ['alignment' => Jc::CENTER];

        $section->addText(strtoupper($header['institute']), ['bold' => true, 'size' => 11], $center);
        $title = $header['title'].($header['subject'] ? " ({$header['subject']})" : '');
        $section->addText($title, ['bold' => true, 'size' => 16], $center);
        if ($header['date']) {
            $section->addText("Examination — {$header['date']}", ['size' => 12], $center);
        }
        $section->addText(
            "Duration: {$header['duration']} minutes    Maximum Marks: {$header['marks']}",
            ['size' => 11],
            $center
        );

        $section->addTextBreak(1);
        $instructions = $section->addTextRun(['spaceAfter' => 120]);
        $instructions->addText('Instructions: ', ['bold' => true, 'size' => 10]);
        $instructions->addText($header['instructions'], ['size' => 10]);

        $section->addTextBreak(1);

        foreach ($this->vm->sections() as $sec) {
            $section->addText($sec['label'], ['bold' => true, 'size' => 13]);
            if (! empty($sec['note'])) {
                $section->addText($sec['note'], ['italic' => true, 'size' => 10, 'color' => '666666']);
            }
            $section->addTextBreak(1);

            foreach ($sec['questions'] as $q) {
                // Extracted questions may embed markdown tables — the first prose
                // segment shares the numbered line; tables become real Word tables.
                $segments = QuestionTextSegments::parse($q['text']);

                $line = $section->addTextRun(['spaceAfter' => 120]);
                $line->addText("{$q['no']}.  ", ['bold' => true]);
                if (($segments[0]['kind'] ?? null) === 'prose') {
                    $line->addText(array_shift($segments)['text']);
                }
                $line->addText("    [{$q['marks']} Marks]", ['bold' => true, 'size' => 10, 'color' => '444444']);

                foreach ($segments as $seg) {
                    if ($seg['kind'] === 'prose') {
                        $section->addText($seg['text'], [], ['spaceAfter' => 120, 'indentation' => ['left' => 360]]);

                        continue;
                    }

                    $table = $section->addTable([
                        'borderSize' => 4,
                        'borderColor' => '555555',
                        'cellMargin' => 60,
                        'indentation' => 360,
                    ]);
                    foreach ($seg['rows'] as $index => $row) {
                        $table->addRow();
                        foreach ($row as $cell) {
                            $table->addCell(1800)->addText($cell, ['bold' => $index === 0, 'size' => 10]);
                        }
                    }
                    $section->addTextBreak(1);
                }
            }

            $section->addTextBreak(1);
        }

        $path = tempnam(sys_get_temp_dir(), 'qforge_docx_').'.docx';
        $word->save($path, 'Word2007');

        return $path;
    }
}
