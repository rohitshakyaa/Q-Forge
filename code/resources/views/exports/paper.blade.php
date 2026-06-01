<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $header['title'] }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a1a; font-size: 12px; margin: 0; }
        .head { text-align: center; border-bottom: 1px solid #999; padding-bottom: 12px; margin-bottom: 6px; }
        .institute { font-size: 12px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .title { font-size: 18px; font-weight: bold; margin: 6px 0 2px; }
        .date { font-size: 13px; margin-bottom: 8px; }
        .meta { font-size: 12px; }
        .meta span { margin: 0 14px; }
        .instructions { font-size: 11px; margin: 10px 0 18px; }
        .section-label { background: #f0f0f0; padding: 6px 10px; font-weight: bold; font-size: 13px; margin-top: 16px; }
        .section-note { font-style: italic; color: #666; font-size: 10.5px; margin: 4px 0 8px; }
        .q { margin-bottom: 10px; }
        .q-no { font-weight: bold; color: #0a7; }
        .q-marks { font-weight: bold; color: #444; float: right; }
        .q-tags { font-size: 10px; color: #555; margin-top: 2px; }
        .tag { display: inline-block; border: 1px solid #ccc; border-radius: 3px; padding: 1px 5px; margin-right: 4px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="institute">{{ $header['institute'] }}</div>
        <div class="title">{{ $header['title'] }}@if($header['subject']) ({{ $header['subject'] }})@endif</div>
        @if($header['date'])<div class="date">Examination — {{ $header['date'] }}</div>@endif
        <div class="meta">
            <span>Duration: {{ $header['duration'] }} minutes</span>
            <span>Maximum Marks: {{ $header['marks'] }}</span>
        </div>
    </div>

    <div class="instructions"><strong>Instructions:</strong> {{ $header['instructions'] }}</div>

    @foreach($sections as $sec)
        <div class="section-label">{{ $sec['label'] }}</div>
        @if(!empty($sec['note']))<div class="section-note">{{ $sec['note'] }}</div>@endif
        @foreach($sec['questions'] as $q)
            <div class="q">
                <span class="q-marks">[{{ $q['marks'] }} Marks]</span>
                <span class="q-no">{{ $q['no'] }}.</span> {{ $q['text'] }}
                @if($q['unit'] || $q['ai'])
                    <div class="q-tags">
                        @if($q['unit'])<span class="tag">{{ $q['unit'] }}</span>@endif
                        @if($q['ai'])<span class="tag">AI Generated</span>@endif
                    </div>
                @endif
            </div>
        @endforeach
    @endforeach
</body>
</html>
