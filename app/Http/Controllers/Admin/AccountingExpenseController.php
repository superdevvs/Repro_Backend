<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountingExpense;
use App\Models\PhotographerEquipment;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AccountingExpenseController extends Controller
{
    public function index(Request $request)
    {
        if (!$this->expenseTableReady()) {
            return $this->expenseTableMissingResponse();
        }

        $query = AccountingExpense::query()->latest('expense_date')->latest();

        if ($request->filled('category') && $request->input('category') !== 'all') {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('related_type')) {
            $query->where('related_type', $request->input('related_type'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->input('date_to'));
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('vendor', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->get()->map(fn (AccountingExpense $expense) => $this->presentExpense($expense))->all(),
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->expenseTableReady()) {
            return $this->expenseTableMissingResponse();
        }

        $validated = $this->validateExpense($request);
        $expense = AccountingExpense::create($validated + [
            'created_by' => $request->user()->id,
        ]);

        $this->storeReceipt($expense, $request->file('receipt'));
        $this->syncLinkedEquipmentFromExpense($expense);

        return response()->json([
            'message' => 'Expense created successfully.',
            'data' => $this->presentExpense($expense->fresh()),
        ], 201);
    }

    public function show(AccountingExpense $expense)
    {
        return response()->json(['data' => $this->presentExpense($expense)]);
    }

    public function update(Request $request, AccountingExpense $expense)
    {
        $validated = $this->validateExpense($request, true);
        $expense->fill($validated)->save();

        if ($request->hasFile('receipt')) {
            $this->deleteReceipt($expense);
            $this->storeReceipt($expense, $request->file('receipt'));
        }

        $this->syncLinkedEquipmentFromExpense($expense->fresh());

        return response()->json([
            'message' => 'Expense updated successfully.',
            'data' => $this->presentExpense($expense->fresh()),
        ]);
    }

    public function destroy(AccountingExpense $expense)
    {
        $this->unlinkEquipmentExpense($expense);
        $this->deleteReceipt($expense);
        $expense->delete();

        return response()->json(['message' => 'Expense deleted successfully.']);
    }

    public function uploadReceipt(Request $request, AccountingExpense $expense)
    {
        $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $this->deleteReceipt($expense);
        $this->storeReceipt($expense, $request->file('receipt'));

        return response()->json([
            'message' => 'Receipt uploaded successfully.',
            'data' => $this->presentExpense($expense->fresh()),
        ]);
    }

    public function showReceipt(AccountingExpense $expense)
    {
        if (!$expense->receipt_disk || !$expense->receipt_path || !Storage::disk($expense->receipt_disk)->exists($expense->receipt_path)) {
            abort(404);
        }

        return response()->file(Storage::disk($expense->receipt_disk)->path($expense->receipt_path), [
            'Content-Type' => $expense->receipt_mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . addslashes($expense->receipt_original_name ?: basename($expense->receipt_path)) . '"',
        ]);
    }

    private function validateExpense(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'category' => [$partial ? 'sometimes' : 'nullable', 'string', 'max:100'],
            'description' => [$required, 'string', 'max:500'],
            'amount' => [$required, 'numeric', 'min:0'],
            'expense_date' => [$required, 'date'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'status' => [$partial ? 'sometimes' : 'nullable', Rule::in(AccountingExpense::STATUSES)],
            'reimbursable' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'tags' => ['nullable'],
            'related_type' => ['nullable', 'string', 'max:100'],
            'related_id' => ['nullable', 'integer'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        if (array_key_exists('tags', $validated) && is_string($validated['tags'])) {
            $decoded = json_decode($validated['tags'], true);
            $validated['tags'] = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        $validated['category'] = $validated['category'] ?? 'General';
        $validated['status'] = $validated['status'] ?? AccountingExpense::STATUS_UNREVIEWED;
        $validated['reimbursable'] = (bool) ($validated['reimbursable'] ?? false);
        unset($validated['receipt']);

        return $validated;
    }

    private function presentExpense(AccountingExpense $expense): array
    {
        return [
            'id' => $expense->id,
            'category' => $expense->category,
            'description' => $expense->description,
            'amount' => (float) $expense->amount,
            'expense_date' => optional($expense->expense_date)?->toDateString(),
            'vendor' => $expense->vendor,
            'status' => $expense->status,
            'reimbursable' => (bool) $expense->reimbursable,
            'notes' => $expense->notes,
            'tags' => $expense->tags ?? [],
            'related_type' => $expense->related_type,
            'related_id' => $expense->related_id,
            'source_label' => $expense->related_type === AccountingExpense::RELATED_PHOTOGRAPHER_EQUIPMENT ? 'Equipment' : 'Manual',
            'has_receipt' => (bool) $expense->receipt_path,
            'receipt_original_name' => $expense->receipt_original_name,
            'receipt_mime_type' => $expense->receipt_mime_type,
            'receipt_size' => $expense->receipt_size,
            'receipt_url' => $expense->receipt_path ? "/api/admin/accounting-expenses/{$expense->id}/receipt" : null,
            'created_at' => optional($expense->created_at)?->toIso8601String(),
            'updated_at' => optional($expense->updated_at)?->toIso8601String(),
        ];
    }

    private function storeReceipt(AccountingExpense $expense, UploadedFile|array|null $receipt): void
    {
        if (!$receipt instanceof UploadedFile) {
            return;
        }

        $path = $receipt->store("accounting-expenses/{$expense->id}/receipt", 'local');
        $expense->forceFill([
            'receipt_disk' => 'local',
            'receipt_path' => $path,
            'receipt_original_name' => $receipt->getClientOriginalName(),
            'receipt_mime_type' => $receipt->getClientMimeType(),
            'receipt_size' => $receipt->getSize(),
        ])->save();
    }

    private function deleteReceipt(AccountingExpense $expense): void
    {
        if ($expense->receipt_disk && $expense->receipt_path && Storage::disk($expense->receipt_disk)->exists($expense->receipt_path)) {
            Storage::disk($expense->receipt_disk)->delete($expense->receipt_path);
        }
    }

    private function syncLinkedEquipmentFromExpense(AccountingExpense $expense): void
    {
        if ($expense->related_type !== AccountingExpense::RELATED_PHOTOGRAPHER_EQUIPMENT || !$expense->related_id) {
            return;
        }

        PhotographerEquipment::query()
            ->whereKey($expense->related_id)
            ->update([
                'purchase_cost' => $expense->amount,
                'purchase_date' => $expense->expense_date,
                'vendor' => $expense->vendor,
                'expense_id' => $expense->id,
            ]);
    }

    private function unlinkEquipmentExpense(AccountingExpense $expense): void
    {
        if ($expense->related_type !== AccountingExpense::RELATED_PHOTOGRAPHER_EQUIPMENT) {
            return;
        }

        PhotographerEquipment::query()
            ->where('expense_id', $expense->id)
            ->update(['expense_id' => null]);
    }

    private function expenseTableReady(): bool
    {
        return Schema::hasTable('accounting_expenses');
    }

    private function expenseTableMissingResponse()
    {
        return response()->json([
            'message' => 'Accounting expense tables are not available yet. Run backend migrations before using Expense Center.',
            'setup_required' => 'php artisan migrate',
        ], 503);
    }
}
