<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\BookCompiler;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class BookController extends Controller
{
    /** In-app reader: "Chronicle of [Character]", readable like a book. */
    public function show(Request $request, Campaign $campaign, BookCompiler $compiler): Response
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        return Inertia::render('Book/Reader', [
            'campaign' => $campaign->only(['id', 'name', 'status']),
            'book' => $compiler->compile($campaign),
        ]);
    }

    /**
     * Downloadable keepsake: a print-styled standalone HTML document (use
     * the browser's print-to-PDF). All machinery stays out of the book.
     */
    public function download(Request $request, Campaign $campaign, BookCompiler $compiler): HttpResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $book = $compiler->compile($campaign);
        $html = view('book', ['book' => $book])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $book['title']).'.html"',
        ]);
    }
}
