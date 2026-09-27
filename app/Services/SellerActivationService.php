<?php

namespace App\Services;

use App\Models\AccountActivationCase;
use App\Models\BusinessSetting;
use App\Models\Seller;
use App\Models\SellerActivationTicket;
use App\Models\SellerActivationTicketMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SellerActivationService
{
    public const SETTING_AUTOMATIC_MESSAGE = 'seller_activation_automatic_message';
    public const AUTOMATIC_MESSAGE = 'Our support team is reviewing your seller account activation request.';

    public function automaticMessage(): string
    {
        try {
            return (string) (BusinessSetting::query()
                ->where('type', self::SETTING_AUTOMATIC_MESSAGE)
                ->value('value') ?: translate('seller_activation_automatic_message_default'));
        } catch (Throwable) {
            return self::AUTOMATIC_MESSAGE;
        }
    }

    public function openRegistrationTicket(Seller $seller): SellerActivationTicket
    {
        return DB::transaction(function () use ($seller) {
            $ticketQuery = SellerActivationTicket::query()->where('seller_id', $seller->id)
                ->when(Schema::hasColumn('seller_activation_tickets', 'hidden_from_subject_at'), fn ($query) => $query->whereNull('hidden_from_subject_at'))
                ->whereIn('status', [
                    SellerActivationTicket::STATUS_OPEN,
                    SellerActivationTicket::STATUS_AWAITING_SELLER,
                    SellerActivationTicket::STATUS_UNDER_REVIEW,
                ])->lockForUpdate();
            $ticket = $ticketQuery->first();

            // Older installations may not have the archive column yet. Never
            // open a second active ticket in that case.
            if (!$ticket) {
                $ticket = SellerActivationTicket::query()->where('seller_id', $seller->id)
                    ->whereIn('status', [SellerActivationTicket::STATUS_OPEN, SellerActivationTicket::STATUS_AWAITING_SELLER, SellerActivationTicket::STATUS_UNDER_REVIEW])
                    ->latest('id')->lockForUpdate()->first();
            }

            if (! $ticket) {
                $ticket = SellerActivationTicket::query()->create([
                    'seller_id' => $seller->id,
                    'status' => SellerActivationTicket::STATUS_OPEN,
                    'subject' => $this->safeTranslate('seller_account_activation', 'Seller account activation'),
                    'opened_at' => now(),
                ]);
                SellerActivationTicketMessage::query()->create([
                    'seller_activation_ticket_id' => $ticket->id,
                    'sender_type' => SellerActivationTicketMessage::SENDER_SYSTEM,
                    'body' => $this->automaticMessage(),
                    'is_automatic' => true,
                ]);
            }

            $seller->forceFill([
                'activation_status' => 'activation_ticket_open',
                'activation_requested_at' => $seller->activation_requested_at ?? now(),
            ])->save();

            if (Schema::hasTable('account_activation_cases')) {
                AccountActivationCase::query()->updateOrCreate([
                    'subject_type' => AccountActivationCase::SUBJECT_SELLER,
                    'subject_id' => $seller->id,
                ], [
                    'source_type' => 'seller_activation_ticket',
                    'source_id' => $ticket->id,
                    'status' => AccountActivationCase::STATUS_PENDING,
                    'metadata' => ['created_from' => 'seller_registration'],
                ]);
            }

            return $ticket;
        });
    }

    private function safeTranslate(string $key, string $fallback): string
    {
        try {
            return (string) translate($key);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
