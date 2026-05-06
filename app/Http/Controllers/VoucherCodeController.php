<?php

namespace App\Http\Controllers;

use App\Models\VoucherCode;
use App\Support\CustomerAccountSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class VoucherCodeController extends Controller
{
    public function index(Request $request, CustomerAccountSessionToken $sessionToken): JsonResponse
    {
        try {
            $claims = $sessionToken->validateRequest($request);
            $customerId = $sessionToken->customerIdFromClaims($claims);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        if ($customerId === null) {
            return response()->json(['message' => 'Customer account session token does not include a customer ID.'], 401);
        }

        $requestedCustomerId = $sessionToken->normalizeCustomerId((string) $request->query('customer_id', ''));
        if ($requestedCustomerId !== null && $requestedCustomerId !== $customerId) {
            return response()->json(['message' => 'Customer account session token does not match the requested customer.'], 403);
        }

        $vouchers = VoucherCode::query()
            ->where('shopify_customer_id', $customerId)
            ->orderByDesc('order_number')
            ->orderBy('product_title')
            ->orderBy('unit_index')
            ->get()
            ->map(function (VoucherCode $voucher): array {
                return [
                    'order_number' => $voucher->order_number,
                    'product_title' => $voucher->product_title,
                    'variant_title' => $voucher->variant_title,
                    'amount' => $voucher->amount,
                    'currency' => $voucher->currency,
                    'code' => $voucher->status === 'active' ? $voucher->code : null,
                    'masked_code' => $voucher->masked_code,
                    'status' => $voucher->status,
                    'source' => $voucher->source,
                    'message' => $this->statusMessage($voucher),
                    'created_at' => optional($voucher->created_at)->toISOString(),
                ];
            })
            ->values();

        return response()->json([
            'customer_id' => $customerId,
            'vouchers' => $vouchers,
        ]);
    }

    private function statusMessage(VoucherCode $voucher): ?string
    {
        if ($voucher->status === 'native_unavailable') {
            return 'Dieser Gutschein wurde von Shopifys nativer Gutschein-Funktion erstellt. Der vollständige Code ist nachträglich nicht über die API abrufbar.';
        }

        if ($voucher->status === 'failed') {
            return 'Dieser Gutscheincode konnte noch nicht erstellt werden. Bitte prüfen Sie Ihre E-Mail oder kontaktieren Sie Sushi Catering.';
        }

        if ($voucher->status === 'disabled') {
            return 'Die automatische Gutscheincode-Erstellung ist aktuell deaktiviert.';
        }

        return null;
    }
}
