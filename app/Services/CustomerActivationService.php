<?php

namespace App\Services;

use App\User as LegacyUser;
use App\Models\AccountActivationCase;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerActivationService
{
    public const PURPOSE = 'account_activation';

    public function __construct(private readonly WorkflowFeatureService $features)
    {
    }

    public function openOrCreateTicket(User|LegacyUser $customer): ?SupportTicket
    {
        if (! Schema::hasColumn('support_tickets', 'purpose')) {
            return null;
        }

        if (in_array($customer->activation_status, ['active', 'rejected'], true)) {
            return null;
        }

        return DB::transaction(function () use ($customer) {
            $ticketQuery = SupportTicket::query()->where('customer_id', $customer->id)
                ->where('purpose', self::PURPOSE)
                ->when(Schema::hasColumn('support_tickets', 'hidden_from_subject_at'), fn ($query) => $query->whereNull('hidden_from_subject_at'))
                ->whereNotIn('review_status', ['approved', 'rejected'])
                ->lockForUpdate()->latest('id');
            $ticket = $ticketQuery->first();

            if (!$ticket) {
                $ticket = SupportTicket::query()->where('customer_id', $customer->id)
                    ->where('purpose', self::PURPOSE)
                    ->whereNotIn('review_status', ['approved', 'rejected'])
                    ->latest('id')->lockForUpdate()->first();
            }

            if (! $ticket) {
                $ticket = SupportTicket::query()->create([
                    'customer_id' => $customer->id,
                    'subject' => translate('customer_account_activation'),
                    'type' => 'account_activation',
                    'purpose' => self::PURPOSE,
                    'review_status' => 'pending',
                    'priority' => 'medium',
                    'description' => translate('customer_activation_automatic_message_default'),
                    'attachment' => [],
                    'status' => 'pending',
                ]);
            }

            $customer->forceFill([
                'activation_status' => 'activation_ticket_open',
                'activation_requested_at' => $customer->activation_requested_at ?? now(),
            ])->save();

            if (Schema::hasTable('account_activation_cases')) {
                AccountActivationCase::query()->updateOrCreate([
                    'subject_type' => AccountActivationCase::SUBJECT_CUSTOMER,
                    'subject_id' => $customer->id,
                ], [
                    'source_type' => 'support_ticket',
                    'source_id' => $ticket->id,
                    'status' => AccountActivationCase::STATUS_PENDING,
                    'metadata' => ['created_from' => 'customer_profile_api'],
                ]);
            }

            return $ticket;
        });
    }

    /**
     * Public name used by web and mobile registration flows. Keep the
     * implementation in one place so both channels create the same case.
     */
    public function ensureTicket(User|LegacyUser $customer): ?SupportTicket
    {
        return $this->openOrCreateTicket($customer);
    }

    /**
     * Return the activation contract consumed by the web and mobile clients.
     */
    public function status(User|LegacyUser $customer): array
    {
        return $this->payload($customer);
    }

    /**
     * Compatibility entry point for the legacy support-ticket admin action.
     * The unified activation center uses the same state transitions.
     */
    public function decide(SupportTicket $ticket, string $decision, ?string $note, int $adminId): SupportTicket
    {
        $approved = $decision === 'approve';
        // The web customer guard still returns the legacy App\User model in
        // some installations, while newer flows use App\Models\User. Both
        // point at the same customer record, so support-ticket approval must
        // work with either model instead of failing on a model-class mismatch.
        $customer = User::query()->find($ticket->customer_id)
            ?? LegacyUser::query()->findOrFail($ticket->customer_id);

        return DB::transaction(function () use ($ticket, $customer, $approved, $note, $adminId) {
            $ticketUpdates = [
                'review_status' => $approved ? 'approved' : 'rejected',
                'review_note' => $note,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => now(),
                'status' => $approved ? 'closed' : 'closed',
                'closed_at' => now(),
            ];
            if (Schema::hasColumn('support_tickets', 'hidden_from_subject_at')) {
                $ticketUpdates['hidden_from_subject_at'] = now();
            }
            $ticket->forceFill($ticketUpdates)->save();
            $customer->forceFill([
                'activation_status' => $approved ? 'active' : 'rejected',
                'activation_approved_at' => $approved ? now() : null,
                'activation_approved_by' => $approved ? $adminId : null,
                'is_active' => 1,
            ])->save();

            if (Schema::hasTable('support_ticket_convs')) {
                DB::table('support_ticket_convs')->insert([
                    'support_ticket_id' => $ticket->id,
                    'admin_id' => $adminId,
                    'admin_message' => $note ?: ($approved ? translate('customer_account_activated') : translate('customer_activation_rejected_contact_support')),
                    'position' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $ticket->fresh();
        });
    }

    public function payload(User|LegacyUser $customer): array
    {
        $hasActivationStatus = Schema::hasColumn('users', 'activation_status');
        $active = $hasActivationStatus
            ? $customer->activation_status === 'active'
            : (bool) $customer->is_active;
        $rejected = $hasActivationStatus && $customer->activation_status === 'rejected';
        $ticket = ($active || $rejected) ? null : $this->openOrCreateTicket($customer);

        return [
            'status' => $hasActivationStatus
                ? ($customer->activation_status ?: ($customer->is_active ? 'active' : 'pending'))
                : ($customer->is_active ? 'active' : 'pending'),
            'is_active' => $active,
            'ticket_id' => $ticket?->id,
            'banner' => $active
                ? ['visible' => false, 'state' => 'activated', 'message' => translate('customer_account_activated')]
                : ($rejected ? [
                    'visible' => true,
                    'state' => 'activation_rejected',
                    'message' => translate('customer_activation_rejected_contact_support'),
                    'action' => 'open_support_ticket',
                ] : [
                    'visible' => true,
                    'state' => 'activation_required',
                    'message' => translate('customer_activation_required'),
                    'action' => 'open_activation_ticket',
                ]),
        ];
    }
}
