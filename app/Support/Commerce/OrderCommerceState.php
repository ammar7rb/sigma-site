<?php

namespace App\Support\Commerce;

final class OrderCommerceState
{
    public const PURCHASE_PAYMENT_PENDING = 'purchase_payment_pending';
    public const CUSTOMER_INSURANCE_PENDING = 'customer_insurance_pending';
    public const CUSTOMER_INSURANCE_UNDER_REVIEW = 'customer_insurance_under_review';
    public const PURCHASE_REFUND_PENDING = 'purchase_refund_pending';
    public const PENDING_ADMIN_REVIEW = 'pending_admin_review';
    public const ADMIN_ASSIGNMENT_IN_PROGRESS = 'admin_assignment_in_progress';
    public const SELLER_INSURANCE_PENDING = 'seller_insurance_pending';
    public const SELLER_INSURANCE_UNDER_REVIEW = 'seller_insurance_under_review';
    public const RELEASED_TO_SELLER = 'released_to_seller';
    public const FULFILLMENT_IN_PROGRESS = 'fulfillment_in_progress';
    public const UNDER_INVESTIGATION = 'under_investigation';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SELLER = 'seller';
    public const ACTOR_PAYMENT = 'payment';
    public const ACTOR_SYSTEM = 'system';

    /** @return array<string, array{owner:string, terminal:bool, description:string}> */
    public static function definitions(): array
    {
        return [
            self::PURCHASE_PAYMENT_PENDING => self::definition(self::ACTOR_CUSTOMER, false, 'Customer has not completed the purchase payment.'),
            self::CUSTOMER_INSURANCE_PENDING => self::definition(self::ACTOR_CUSTOMER, false, 'Purchase is paid and the separate customer insurance payment is due.'),
            self::CUSTOMER_INSURANCE_UNDER_REVIEW => self::definition(self::ACTOR_ADMIN, false, 'Customer insurance payment is awaiting confirmation.'),
            self::PURCHASE_REFUND_PENDING => self::definition(self::ACTOR_ADMIN, false, 'Insurance was declined or expired and the purchase refund is scheduled.'),
            self::PENDING_ADMIN_REVIEW => self::definition(self::ACTOR_ADMIN, false, 'Customer insurance is paid and the order awaits the admin decision.'),
            self::ADMIN_ASSIGNMENT_IN_PROGRESS => self::definition(self::ACTOR_ADMIN, false, 'Admin is assigning seller insurance, shipping party and shipping cost.'),
            self::SELLER_INSURANCE_PENDING => self::definition(self::ACTOR_SELLER, false, 'A restricted order was sent to the seller and its insurance is unpaid.'),
            self::SELLER_INSURANCE_UNDER_REVIEW => self::definition(self::ACTOR_ADMIN, false, 'Seller insurance evidence is awaiting confirmation.'),
            self::RELEASED_TO_SELLER => self::definition(self::ACTOR_SELLER, false, 'Seller insurance is accepted and full order details are released.'),
            self::FULFILLMENT_IN_PROGRESS => self::definition(self::ACTOR_SELLER, false, 'Order preparation or shipping is in progress.'),
            self::UNDER_INVESTIGATION => self::definition(self::ACTOR_ADMIN, false, 'The order or an insurance balance is frozen for an admin investigation.'),
            self::COMPLETED => self::definition(self::ACTOR_SYSTEM, true, 'The order flow is complete.'),
            self::CANCELLED => self::definition(self::ACTOR_SYSTEM, true, 'The order is cancelled after its financial actions are recorded.'),
        ];
    }

    /** @return array<string, array<string, list<string>>> */
    public static function transitions(): array
    {
        return [
            self::PURCHASE_PAYMENT_PENDING => [
                self::CUSTOMER_INSURANCE_PENDING => [self::ACTOR_PAYMENT, self::ACTOR_SYSTEM],
                self::CANCELLED => [self::ACTOR_CUSTOMER, self::ACTOR_ADMIN, self::ACTOR_SYSTEM],
            ],
            self::CUSTOMER_INSURANCE_PENDING => [
                self::CUSTOMER_INSURANCE_UNDER_REVIEW => [self::ACTOR_CUSTOMER, self::ACTOR_PAYMENT, self::ACTOR_SYSTEM],
                self::PENDING_ADMIN_REVIEW => [self::ACTOR_PAYMENT, self::ACTOR_ADMIN, self::ACTOR_SYSTEM],
                self::PURCHASE_REFUND_PENDING => [self::ACTOR_CUSTOMER, self::ACTOR_ADMIN, self::ACTOR_SYSTEM],
                self::UNDER_INVESTIGATION => [self::ACTOR_ADMIN],
            ],
            self::CUSTOMER_INSURANCE_UNDER_REVIEW => [
                self::PENDING_ADMIN_REVIEW => [self::ACTOR_ADMIN, self::ACTOR_PAYMENT],
                self::CUSTOMER_INSURANCE_PENDING => [self::ACTOR_ADMIN],
                self::PURCHASE_REFUND_PENDING => [self::ACTOR_ADMIN],
                self::UNDER_INVESTIGATION => [self::ACTOR_ADMIN],
            ],
            self::PENDING_ADMIN_REVIEW => [
                self::ADMIN_ASSIGNMENT_IN_PROGRESS => [self::ACTOR_ADMIN],
                self::PURCHASE_REFUND_PENDING => [self::ACTOR_ADMIN],
                self::UNDER_INVESTIGATION => [self::ACTOR_ADMIN],
            ],
            self::ADMIN_ASSIGNMENT_IN_PROGRESS => [
                self::SELLER_INSURANCE_PENDING => [self::ACTOR_ADMIN],
                self::PURCHASE_REFUND_PENDING => [self::ACTOR_ADMIN],
                self::UNDER_INVESTIGATION => [self::ACTOR_ADMIN],
            ],
            self::SELLER_INSURANCE_PENDING => [
                self::SELLER_INSURANCE_UNDER_REVIEW => [self::ACTOR_SELLER, self::ACTOR_PAYMENT, self::ACTOR_SYSTEM],
                self::RELEASED_TO_SELLER => [self::ACTOR_ADMIN, self::ACTOR_PAYMENT],
                self::UNDER_INVESTIGATION => [self::ACTOR_ADMIN],
            ],
            self::SELLER_INSURANCE_UNDER_REVIEW => [
                self::RELEASED_TO_SELLER => [self::ACTOR_ADMIN, self::ACTOR_PAYMENT],
                self::SELLER_INSURANCE_PENDING => [self::ACTOR_ADMIN],
                self::UNDER_INVESTIGATION => [self::ACTOR_ADMIN],
            ],
            self::RELEASED_TO_SELLER => [
                self::FULFILLMENT_IN_PROGRESS => [self::ACTOR_SELLER, self::ACTOR_ADMIN],
                self::UNDER_INVESTIGATION => [self::ACTOR_ADMIN],
                self::CANCELLED => [self::ACTOR_ADMIN],
            ],
            self::FULFILLMENT_IN_PROGRESS => [
                self::COMPLETED => [self::ACTOR_SELLER, self::ACTOR_ADMIN, self::ACTOR_SYSTEM],
                self::UNDER_INVESTIGATION => [self::ACTOR_ADMIN],
                self::CANCELLED => [self::ACTOR_ADMIN],
            ],
            self::UNDER_INVESTIGATION => [
                self::CUSTOMER_INSURANCE_PENDING => [self::ACTOR_ADMIN],
                self::PENDING_ADMIN_REVIEW => [self::ACTOR_ADMIN],
                self::SELLER_INSURANCE_PENDING => [self::ACTOR_ADMIN],
                self::RELEASED_TO_SELLER => [self::ACTOR_ADMIN],
                self::PURCHASE_REFUND_PENDING => [self::ACTOR_ADMIN],
                self::CANCELLED => [self::ACTOR_ADMIN],
            ],
            self::PURCHASE_REFUND_PENDING => [
                self::CANCELLED => [self::ACTOR_ADMIN, self::ACTOR_SYSTEM],
            ],
            self::COMPLETED => [],
            self::CANCELLED => [],
        ];
    }

    public static function canTransition(string $from, string $to, string $actor): bool
    {
        return in_array($actor, self::transitions()[$from][$to] ?? [], true);
    }

    /** @return list<string> */
    public static function states(): array
    {
        return array_keys(self::definitions());
    }

    /** @return array{owner:string, terminal:bool, description:string} */
    private static function definition(string $owner, bool $terminal, string $description): array
    {
        return compact('owner', 'terminal', 'description');
    }
}
