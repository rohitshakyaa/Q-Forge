<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Exported paper header
    |--------------------------------------------------------------------------
    |
    | Institution name and default exam instructions printed on exported papers
    | (PDF + DOCX). These feed the shared PaperViewModel header so the Blade
    | template and the PhpWord builder render identical letterheads.
    |
    */
    'institute' => env('QFORGE_INSTITUTE', 'Institute of Technology'),

    'paper' => [
        'instructions' => env(
            'QFORGE_PAPER_INSTRUCTIONS',
            'Answer all questions in Section A. Attempt any 3 from Section B. '.
            'Write clearly. Show all workings.'
        ),
    ],
];
