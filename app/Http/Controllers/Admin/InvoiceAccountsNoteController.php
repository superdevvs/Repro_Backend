<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceAccountsNote;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceAccountsNoteController extends Controller
{
    public function show(Invoice $invoice): JsonResponse
    {
        return $this->response($invoice);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate(['note' => ['present', 'nullable', 'string', 'max:5000']]);
        $now = now();

        // One atomic upsert avoids a read-then-write SQLite transaction and preserves created_at.
        LockedWrite::run(fn () => InvoiceAccountsNote::query()->upsert([[
            'invoice_id' => $invoice->id,
            'note' => $validated['note'] ?? '',
            'author_id' => $request->user()->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['invoice_id'], ['note', 'author_id', 'updated_at']), 'invoice-accounts-note');

        // Do not write into the invoice timeline: it is visible to the payee.
        return $this->response($invoice);
    }

    private function response(Invoice $invoice): JsonResponse
    {
        $note = InvoiceAccountsNote::query()->with('author:id,name')->where('invoice_id', $invoice->id)->first();

        return response()->json(['data' => [
            'note' => $note?->note ?? '',
            'author' => $note?->author ? ['id' => $note->author->id, 'name' => $note->author->name] : null,
            'created_at' => $note?->created_at?->toIso8601String(),
            'updated_at' => $note?->updated_at?->toIso8601String(),
        ]]);
    }
}
