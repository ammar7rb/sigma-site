<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShippingProof;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderShippingProofService
{
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['ready_to_ship'],
        'ready_to_ship' => ['shipped'],
        'shipped' => ['delivered'],
        'delivered' => [],
    ];

    public function submit(Order $order, int $sellerId, string $status, UploadedFile $file, ?string $note = null): OrderShippingProof
    {
        $path = null;
        try {
            return DB::transaction(function () use ($order, $sellerId, $status, $file, $note, &$path) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                return $this->storeProof($locked, $sellerId, $status, $file, $note, $path);
            });
        } catch (\Throwable $exception) {
            if ($path) Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    private function storeProof(Order $order, int $sellerId, string $status, UploadedFile $file, ?string $note, ?string &$path): OrderShippingProof
    {
        $sellerIsResponsible = $order->shipping_fulfillment_mode === 'seller_shipping'
            || $order->shipping_responsible_party === 'seller'
            || $order->shipping_responsibility === 'sellerwise_shipping';
        if ((int) $order->seller_id !== $sellerId || ! $sellerIsResponsible) {
            throw ValidationException::withMessages(['order_id' => translate('seller_shipping_is_not_assigned_to_this_order')]);
        }

        if (! app(SellerOrderVisibilityService::class)->canSellerView($order)) {
            throw ValidationException::withMessages(['order_id' => translate('pay_order_insurance_before_accessing_shipping_details')]);
        }

        $current = $order->shipping_workflow_status ?: 'pending';
        if (in_array($order->order_status, ['canceled', 'returned', 'failed', 'delivered'], true)
            || ($status !== 'delivered' && ! in_array($status, self::ALLOWED_TRANSITIONS[$current] ?? [], true))) {
            throw ValidationException::withMessages(['shipping_status' => translate('invalid_shipping_status_transition')]);
        }

        $path = $file->store("shipping-proofs/{$order->id}", 'local');
        $proof = OrderShippingProof::create([
            'order_id' => $order->id,
            'seller_id' => $sellerId,
            'shipping_status' => $status,
            'file_path' => $path,
            'disk' => 'local',
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'note' => $note,
            'review_status' => 'pending',
        ]);

        $order->forceFill(['shipping_workflow_status' => $status])->save();
        if ($status === 'delivered') {
            app(SellerDeliveryCompletionService::class)->complete($order);
            $updates = [
                'shipping_operational_status' => 'delivered',
                'operational_blocked_by' => null,
                'operational_block_reason' => null,
                'operational_status_updated_at' => now(),
            ];
            if (in_array($order->commerce_flow_version, [config('order_commerce.new_flow_version'), PostPurchaseInvoiceService::CONTRACT_VERSION], true)) {
                $updates['commerce_flow_status'] = \App\Support\Commerce\OrderCommerceState::COMPLETED;
                $updates['commerce_flow_status_updated_at'] = now();
            }
            $order->forceFill(array_intersect_key($updates, array_flip(Schema::getColumnListing('orders'))))->save();
            if (Schema::hasTable('order_logistics_events')) {
                app(OrderLogisticsService::class)->record($order->fresh(), 'delivery_proof_completed', 'seller', $sellerId, $note, 'seller', [
                    'event_key' => 'delivery-proof:' . $proof->id,
                    'metadata' => ['proof_id' => $proof->id],
                ]);
            }
        }

        return $proof;
    }

    public function review(OrderShippingProof $proof, string $status, int $adminId, ?string $note = null): OrderShippingProof
    {
        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['review_status' => translate('invalid_review_status')]);
        }

        $proof->update([
            'review_status' => $status,
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        return $proof->refresh();
    }

    public function download(OrderShippingProof $proof): mixed
    {
        abort_unless(Storage::disk($proof->disk)->exists($proof->file_path), 404);

        return Storage::disk($proof->disk)->download($proof->file_path, $proof->original_name ?: basename($proof->file_path));
    }

    public function preview(OrderShippingProof $proof): mixed
    {
        abort_unless(Storage::disk($proof->disk)->exists($proof->file_path), 404);

        return Storage::disk($proof->disk)->response(
            $proof->file_path,
            $proof->original_name ?: basename($proof->file_path),
            ['Content-Disposition' => 'inline']
        );
    }
}
