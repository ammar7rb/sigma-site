<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Order;
use App\Services\ContactPasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SellerShippingSupportController extends Controller
{
    public function open(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $contact = $this->ticket($request, $order, true);

        return $this->payload($contact, $order);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $contact = $this->ticket($request, $order, false);

        if (! $contact) {
            return response()->json([
                'ticket' => null,
                'order' => $this->orderSummary($order),
                'messages' => [],
            ])->header('Cache-Control', 'private, no-store');
        }

        return $this->payload($contact, $order);
    }

    public function reply(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate([
            'message' => 'nullable|string|max:5000|required_without:attachments',
            'attachments' => 'nullable|array|max:5|required_without:message',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:6144',
        ]);
        $contact = $this->ticket($request, $order, true);
        $attachments = collect($request->file('attachments', []))->map(fn ($file) => [
            'name' => $file->getClientOriginalName(),
            'path' => $file->store('support-conversations', 'public'),
        ])->values()->all();

        DB::transaction(function () use ($contact, $data, $attachments): void {
            DB::table('contact_conversation_messages')->insert([
                'contact_id' => $contact->id,
                'sender' => 'requester',
                'body' => trim($data['message'] ?? ''),
                'attachments' => $attachments ? json_encode($attachments) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $contact->update(['seen' => false]);
        });

        return $this->payload($contact->fresh(), $order);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless(
            $order->seller_is === 'seller'
            && (int) $order->seller_id === (int) $request->seller?->id,
            403
        );
    }

    private function ticket(Request $request, Order $order, bool $create): ?Contact
    {
        $hash = hash('sha256', "seller-shipping:{$request->seller->id}:{$order->id}");
        $query = Contact::query()->where('mobile_support_token_hash', $hash);
        if (! $create) {
            return $query->first();
        }

        $seller = $request->seller;
        $name = trim(implode(' ', array_filter([$seller->f_name ?? null, $seller->l_name ?? null])));

        return Contact::query()->firstOrCreate([
            'mobile_support_token_hash' => $hash,
        ], [
            'name' => $name !== '' ? $name : "Seller #{$seller->id}",
            'email' => $seller->email ?? '',
            'mobile_number' => $seller->phone ?? '',
            'subject' => "اعتراض على مبلغ الشحن - طلب #{$order->id}",
            'message' => "أرغب في تقديم اعتراض على مبلغ الشحن للطلب #{$order->id}.",
            'seen' => false,
        ]);
    }

    private function payload(Contact $contact, Order $order): JsonResponse
    {
        $messages = DB::table('contact_conversation_messages')
            ->where('contact_id', $contact->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($message) => [
                'id' => $message->id,
                'sender' => $message->sender === 'requester' ? 'requester' : 'admin',
                'body' => ContactPasswordResetService::displayBody($message),
                'attachments' => collect(json_decode($message->attachments ?? '[]', true))
                    ->map(fn ($file) => [
                        'name' => $file['name'] ?? '',
                        'url' => isset($file['path']) ? Storage::disk('public')->url($file['path']) : null,
                    ])->values(),
                'created_at' => $message->created_at,
            ]);

        return response()->json([
            'ticket' => ['id' => $contact->id, 'subject' => $contact->subject],
            'order' => $this->orderSummary($order),
            'initial_message' => $contact->message,
            'messages' => $messages,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function orderSummary(Order $order): array
    {
        return [
            'id' => $order->id,
            'reference' => '#'.$order->id,
            'shipping_entitlement' => (float) ($order->shipping_seller_entitlement ?? $order->shipping_seller_cost ?? 0),
        ];
    }
}
