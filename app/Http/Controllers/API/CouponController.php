<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index()
    {
        $coupons = Coupon::orderByDesc('created_at')->get();

        return response()->json([
            'data' => $coupons,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:coupons,code',
            'type' => 'required|in:percentage,fixed',
            'amount' => 'required|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'valid_until' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['created_by'] = $request->user()->id ?? null;

        $coupon = Coupon::create($validated);

        return response()->json([
            'message' => 'Coupon created successfully.',
            'data' => $coupon,
        ], 201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|max:50|unique:coupons,code,' . $coupon->id,
            'type' => 'sometimes|in:percentage,fixed',
            'amount' => 'sometimes|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'valid_until' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        $coupon->update($validated);

        return response()->json([
            'message' => 'Coupon updated successfully.',
            'data' => $coupon->fresh(),
        ]);
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return response()->json([
            'message' => 'Coupon deleted successfully.',
        ]);
    }

    public function toggleStatus(Coupon $coupon)
    {
        $coupon->is_active = !$coupon->is_active;
        $coupon->save();

        return response()->json([
            'message' => 'Coupon status updated.',
            'data' => $coupon->fresh(),
        ]);
    }

    public function validateCoupon(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50',
            'amount' => 'nullable|numeric|min:0',
        ]);

        $coupon = Coupon::query()
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($validated['code']))])
            ->where('is_active', true)
            ->first();

        if (!$coupon) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid coupon code.',
            ], 404);
        }

        if ($coupon->valid_until && $coupon->valid_until->isPast()) {
            return response()->json([
                'valid' => false,
                'message' => 'This coupon has expired.',
            ], 422);
        }

        if ($coupon->max_uses !== null && (int) $coupon->current_uses >= (int) $coupon->max_uses) {
            return response()->json([
                'valid' => false,
                'message' => 'This coupon is no longer available.',
            ], 422);
        }

        $amount = max((float) ($validated['amount'] ?? 0), 0);
        $discountAmount = 0.0;

        if ($amount > 0) {
            $discountAmount = match ($coupon->type) {
                'percentage' => round($amount * min((float) $coupon->amount, 100) / 100, 2),
                'fixed' => round(min((float) $coupon->amount, $amount), 2),
                default => 0.0,
            };
        }

        return response()->json([
            'valid' => true,
            'code' => $coupon->code,
            'discount' => (float) $coupon->amount,
            'discount_type' => $coupon->type,
            'discount_amount' => $discountAmount,
            'data' => $coupon,
        ]);
    }
}





