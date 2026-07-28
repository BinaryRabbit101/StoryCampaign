<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $book['title'] }}</title>
    <style>
        @page { margin: 2.5cm; }
        body {
            font-family: Georgia, 'Times New Roman', serif;
            color: #1a1a1a;
            max-width: 34rem;
            margin: 0 auto;
            padding: 3rem 1.5rem;
            line-height: 1.7;
        }
        .title-page { text-align: center; padding: 6rem 0 4rem; page-break-after: always; }
        .title-page h1 { font-size: 2.2rem; font-weight: normal; letter-spacing: 0.02em; margin-bottom: 0.5rem; }
        .title-page .character { font-style: italic; color: #555; }
        .title-page .dates { margin-top: 3rem; font-size: 0.9rem; color: #777; }
        .back-cover { margin-top: 2rem; font-style: italic; color: #444; }
        .chapter { margin-top: 3.5rem; page-break-inside: avoid; }
        .chapter h2 {
            font-size: 1rem; font-weight: normal; letter-spacing: 0.25em;
            text-transform: uppercase; color: #888; text-align: center;
        }
        .intent-line { text-align: center; font-style: italic; color: #666; margin: 0.75rem 0 1.5rem; }
        .chronicle-mark { text-align: center; color: #999; letter-spacing: 0.5em; margin-bottom: 1rem; }
        .body p { text-indent: 1.5em; margin: 0 0 0.2rem; }
        .body p:first-child { text-indent: 0; }
        .shelf { margin-top: 4rem; page-break-before: always; }
        .shelf h2 {
            font-size: 1rem; font-weight: normal; letter-spacing: 0.25em;
            text-transform: uppercase; color: #888; text-align: center;
        }
        .shelf .keepsake { margin-top: 2rem; text-align: center; page-break-inside: avoid; }
        .shelf .keepsake .line { font-style: italic; color: #444; margin-top: 0.25rem; }
        .shelf .keepsake .cite { font-size: 0.85rem; color: #888; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <div class="title-page">
        <h1>{{ $book['title'] }}</h1>
        @if ($book['character'])
            <div class="character">the tale of {{ $book['character'] }}</div>
        @endif
        <div class="dates">
            {{ $book['started_at'] }} — {{ $book['ended_at'] ?? 'ongoing' }}
            @if ($book['ended_early']) <br>ended where the teller chose to leave it @endif
        </div>
        @if ($book['back_cover'])
            <div class="back-cover">{{ $book['back_cover'] }}</div>
        @endif
    </div>

    @foreach ($book['chapters'] as $chapter)
        <div class="chapter">
            @if ($chapter['kind'] === 'prologue')
                <h2>Prologue</h2>
            @elseif ($chapter['kind'] === 'coda')
                <h2>Coda</h2>
            @elseif ($chapter['kind'] === 'chronicle')
                <div class="chronicle-mark">✦ ✦ ✦</div>
            @else
                <h2>Chapter {{ $chapter['number'] }}</h2>
            @endif

            @if ($chapter['intent_line'])
                <div class="intent-line">{{ $chapter['intent_line'] }}</div>
            @endif

            <div class="body">
                @foreach (preg_split('/\n\s*\n/', $chapter['body']) as $paragraph)
                    <p>{{ trim($paragraph) }}</p>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- What you carried home. Compiled, not written: the words were set down
         when each moment happened. Nothing at all when the shelf is empty. --}}
    @if (!empty($book['mementos']))
        <div class="shelf">
            <h2>What you carried home</h2>
            @foreach ($book['mementos'] as $memento)
                <div class="keepsake">
                    <div class="name">{{ $memento['name'] }}</div>
                    <div class="line">{{ $memento['line'] }}</div>
                    @if ($memento['chapter'])
                        <div class="cite">— chapter {{ $memento['chapter'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</body>
</html>
