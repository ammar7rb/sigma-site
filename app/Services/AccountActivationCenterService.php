<?php

namespace App\Services;

use App\Models\AccountActivationCase;
use App\Models\AccountActivationCaseEvent;
use App\Models\AccountActivationDocument;
use App\Models\AccountActivationProfileField;
use App\Models\Seller;
use App\Models\SellerActivationTicketMessage;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AccountActivationCenterService
{
    public function __construct(private readonly WorkflowFeatureService $features)
    {
    }

    public function synchronizeLegacyCases(): int
    {
        if (! $this->features->activationCenterEnabled() || ! Schema::hasTable('account_activation_cases')) {
            return 0;
        }

        $count = $this->synchronizeCustomerTickets();
        $count += $this->synchronizeSellerTickets();

        return $count;
    }

    public function assign(AccountActivationCase $case, int $assignedAdminId, int $actorAdminId): AccountActivationCase
    {
        return DB::transaction(function () use ($case, $assignedAdminId, $actorAdminId) {
            $locked = AccountActivationCase::query()->lockForUpdate()->findOrFail($case->id);
            $this->assertEditable($locked);
            $from = $locked->status;
            $locked->update([
                'assigned_admin_id' => $assignedAdminId,
                'assigned_at' => now(),
                'status' => $locked->isCompleted() ? $locked->status : AccountActivationCase::STATUS_IN_REVIEW,
            ]);
            $this->event($locked, 'case_assigned', $actorAdminId, $from, $locked->status, null, [
                'assigned_admin_id' => $assignedAdminId,
            ]);

            return $locked->fresh();
        });
    }

    public function saveField(AccountActivationCase $case, array $data, int $adminId): AccountActivationProfileField
    {
        return DB::transaction(function () use ($case, $data, $adminId) {
            $this->assertEditable($case);
            $fieldKey = $this->resolveProfileFieldKey($case, (string) $data['label']);
            $field = AccountActivationProfileField::query()->firstOrNew([
                'account_activation_case_id' => $case->id,
                'field_key' => $fieldKey,
            ]);
            $created = ! $field->exists;
            $field->fill([
                'label' => $data['label'],
                'value' => $data['value'] ?? null,
                'is_sensitive' => (bool) ($data['is_sensitive'] ?? true),
                'is_required' => (bool) ($data['is_required'] ?? false),
                'is_verified' => (bool) ($data['is_verified'] ?? false),
                'created_by_admin_id' => $field->created_by_admin_id ?: $adminId,
                'verified_by_admin_id' => ! empty($data['is_verified']) ? $adminId : null,
                'verified_at' => ! empty($data['is_verified']) ? now() : null,
                'metadata' => ['source' => 'activation_center'],
            ])->save();

            // Keep the flexible activation record as the audit source, while
            // mirroring known identity/contact fields into the real profile.
            // Unknown keys (for example a tax-card number) remain safely in
            // the activation profile without changing the schema at runtime.
            $this->syncFieldToSubjectProfile($case, $field->field_key, $field->value);

            $this->event($case, $created ? 'profile_field_added' : 'profile_field_updated', $adminId, null, null, null, [
                'field_key' => $field->field_key,
                'is_required' => $field->is_required,
                'is_verified' => $field->is_verified,
            ]);

            return $field->fresh();
        });
    }

    /**
     * Technical keys are an internal concern. Administrators only name the
     * information in plain language; this method provides a stable safe key
     * and mirrors the few known fields to the subject profile when possible.
     */
    private function resolveProfileFieldKey(AccountActivationCase $case, string $label): string
    {
        $normalized = Str::lower(trim($label));
        $knownKeys = [
            'first name' => 'f_name', 'first_name' => 'f_name', 'الاسم الأول' => 'f_name', 'الاسم الاول' => 'f_name',
            'last name' => 'l_name', 'last_name' => 'l_name', 'اسم العائلة' => 'l_name',
            'email' => 'email', 'e-mail' => 'email', 'البريد الإلكتروني' => 'email', 'البريد الالكتروني' => 'email',
            'phone' => 'phone', 'mobile' => 'phone', 'الهاتف' => 'phone', 'رقم الهاتف' => 'phone',
            'additional phone' => 'additional_phone', 'رقم هاتف إضافي' => 'additional_phone', 'رقم هاتف اضافي' => 'additional_phone',
            'address' => 'additional_address', 'العنوان' => 'additional_address',
            'shop name' => 'shop_name', 'اسم المتجر' => 'shop_name',
            'shop address' => 'shop_address', 'عنوان المتجر' => 'shop_address',
            'tax number' => 'shop_tax_identification_number', 'الرقم الضريبي' => 'shop_tax_identification_number',
        ];

        if (isset($knownKeys[$normalized])) {
            return $knownKeys[$normalized];
        }

        $key = trim(Str::snake(Str::ascii($label)), '_');
        if ($key !== '') {
            return Str::limit($key, 80, '');
        }

        return 'field_'.substr(sha1($case->id.'|'.mb_strtolower($label)), 0, 16);
    }

    private function syncFieldToSubjectProfile(AccountActivationCase $case, string $key, mixed $value): void
    {
        $key = Str::snake($key);
        $value = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($case->subject_type === AccountActivationCase::SUBJECT_SELLER) {
            $seller = Seller::query()->find($case->subject_id);
            if (!$seller) return;

            $sellerColumns = Schema::getColumnListing($seller->getTable());
            if (in_array($key, ['f_name', 'l_name', 'email', 'phone', 'additional_phone', 'additional_address', 'street_address', 'city', 'state', 'zip'], true)
                && in_array($key, $sellerColumns, true)) {
                $seller->forceFill([$key => $value])->save();
                return;
            }

            if (str_starts_with($key, 'shop_')) {
                // The activation service is also used by the isolated test
                // harness, where a seller can exist before the optional shops
                // table is created.  Do not make saving a profile field depend
                // on that optional relation being available.
                if (! Schema::hasTable('shops')) {
                    return;
                }

                $shopKey = Str::after($key, 'shop_');
                $shop = $seller->shop;
                if ($shop && in_array($shopKey, ['name', 'address', 'contact', 'tax_identification_number', 'additional_address'], true)
                    && in_array($shopKey, Schema::getColumnListing($shop->getTable()), true)) {
                    $shop->forceFill([$shopKey => $value])->save();
                }
            }
            return;
        }

        $customer = User::query()->find($case->subject_id);
        if (!$customer) return;
        $columns = Schema::getColumnListing($customer->getTable());
        if (in_array($key, ['f_name', 'l_name', 'email', 'phone', 'additional_phone', 'additional_address', 'street_address', 'city', 'state', 'zip'], true)
            && in_array($key, $columns, true)) {
            $customer->forceFill([$key => $value])->save();
        }
    }

    public function storeDocument(AccountActivationCase $case, array $data, UploadedFile $file, int $adminId): AccountActivationDocument
    {
        $this->assertEditable($case);
        $disk = (string) config('account_workflows.activation_center.private_disk', 'local');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $encryptAtRest = (bool) config('account_workflows.activation_center.encrypt_documents', true);
        $filename = Str::uuid()->toString().'.'.$extension.($encryptAtRest ? '.enc' : '');
        $directory = 'activation-documents/'.$case->id;
        $path = $directory.'/'.$filename;
        $stored = $encryptAtRest
            ? Storage::disk($disk)->put($path, Crypt::encryptString((string) file_get_contents($file->getRealPath())))
            : Storage::disk($disk)->putFileAs($directory, $file, $filename);

        if (! $stored) {
            throw new DomainException('activation_document_storage_failed');
        }

        $document = AccountActivationDocument::query()->create([
            'account_activation_case_id' => $case->id,
            'document_type' => $data['document_type'],
            'custom_name' => $data['document_type'] === 'other' ? trim((string) $data['other_document_name']) : null,
            'document_number' => $data['document_number'] ?? null,
            'original_name' => $file->getClientOriginalName(),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'expires_at' => $data['expires_at'] ?? null,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'verification_status' => ! empty($data['is_verified']) ? 'verified' : 'pending',
            'uploaded_by_admin_id' => $adminId,
            'verified_by_admin_id' => ! empty($data['is_verified']) ? $adminId : null,
            'verified_at' => ! empty($data['is_verified']) ? now() : null,
            'metadata' => ['source' => 'activation_center', 'encrypted_at_rest' => $encryptAtRest],
        ]);

        $this->event($case, 'document_added', $adminId, null, null, null, [
            'document_id' => $document->id,
            'document_type' => $document->document_type,
            'document_name' => $document->custom_name,
            'is_required' => $document->is_required,
            'verification_status' => $document->verification_status,
            'sha256' => $document->sha256,
        ]);

        return $document;
    }

    public function verifyDocument(AccountActivationCase $case, AccountActivationDocument $document, string $status, int $adminId): AccountActivationDocument
    {
        $this->assertEditable($case);
        if ((int) $document->account_activation_case_id !== (int) $case->id) {
            throw new DomainException('activation_document_case_mismatch');
        }

        $document->update([
            'verification_status' => $status,
            'verified_by_admin_id' => $adminId,
            'verified_at' => now(),
        ]);
        $this->event($case, 'document_verification_changed', $adminId, null, null, null, [
            'document_id' => $document->id,
            'verification_status' => $status,
        ]);

        return $document->fresh();
    }

    public function recordDocumentAccess(AccountActivationCase $case, AccountActivationDocument $document, int $adminId): void
    {
        if ((int) $document->account_activation_case_id !== (int) $case->id) {
            throw new DomainException('activation_document_case_mismatch');
        }

        $this->event($case, 'document_downloaded', $adminId, null, null, null, [
            'document_id' => $document->id,
            'document_type' => $document->document_type,
            'sha256' => $document->sha256,
        ]);
    }

    public function reply(AccountActivationCase $case, string $message, int $adminId): void
    {
        if ($case->isCompleted()) {
            throw new DomainException('completed_activation_case_is_read_only');
        }

        if ($case->source_type === 'support_ticket' && Schema::hasTable('support_ticket_convs')) {
            DB::table('support_ticket_convs')->insert([
                'support_ticket_id' => $case->source_id,
                'admin_id' => $adminId,
                'admin_message' => $message,
                'position' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('support_tickets')->where('id', $case->source_id)->update(['status' => 'open', 'updated_at' => now()]);
        } elseif ($case->source_type === 'seller_activation_ticket' && Schema::hasTable('seller_activation_ticket_messages')) {
            DB::table('seller_activation_ticket_messages')->insert([
                'seller_activation_ticket_id' => $case->source_id,
                'sender_type' => 'admin',
                'sender_admin_id' => $adminId,
                'body' => $message,
                'attachments' => null,
                'is_automatic' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            throw new DomainException('activation_case_conversation_source_not_available');
        }

        $this->event($case, 'admin_replied', $adminId, null, null, null, ['message_length' => mb_strlen($message)]);
    }

    public function requestCompletion(AccountActivationCase $case, string $note, int $adminId): AccountActivationCase
    {
        return $this->transition($case, AccountActivationCase::STATUS_NEEDS_INFORMATION, $note, $adminId, false);
    }

    public function reject(AccountActivationCase $case, string $note, int $adminId): AccountActivationCase
    {
        return $this->transition($case, AccountActivationCase::STATUS_REJECTED, $note, $adminId, true);
    }

    public function approve(AccountActivationCase $case, string|null $note, int $adminId): AccountActivationCase
    {
        return DB::transaction(function () use ($case, $note, $adminId) {
            $locked = AccountActivationCase::query()->lockForUpdate()->findOrFail($case->id);
            $this->assertEditable($locked);
            $this->assertRequiredEvidenceIsVerified($locked);
            $from = $locked->status;

            if ($locked->subject_type === AccountActivationCase::SUBJECT_SELLER) {
                $this->activateSeller($locked->subject_id, $adminId);
            } else {
                $this->activateCustomer($locked->subject_id, $adminId);
            }

            $locked->update([
                'status' => AccountActivationCase::STATUS_APPROVED,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => now(),
                'decision_note' => $note,
                'subject_hidden_at' => now(),
                'completed_at' => now(),
            ]);
            $this->completeSource($locked, true, $adminId, $note);
            $this->event($locked, 'case_approved', $adminId, $from, AccountActivationCase::STATUS_APPROVED, $note);

            return $locked->fresh();
        });
    }

    public function sourceMessages(AccountActivationCase $case): Collection
    {
        if ($case->source_type === 'support_ticket' && Schema::hasTable('support_tickets')) {
            $ticket = DB::table('support_tickets')->where('id', $case->source_id)->first();
            $initial = $ticket && $ticket->description ? collect([[
                'sender_type' => 'customer', 'body' => $ticket->description,
                'created_at' => $ticket->created_at,
                'attachments' => $this->normalizeMessageAttachments($ticket->attachment ?? null),
            ]]) : collect();
            $conversation = Schema::hasTable('support_ticket_convs')
                ? DB::table('support_ticket_convs')->where('support_ticket_id', $case->source_id)->orderBy('id')->get()->map(fn ($row) => [
                    'id' => $row->id,
                    'sender_type' => $row->admin_message ? 'admin' : 'customer',
                    'body' => $row->admin_message ?: $row->customer_message,
                    'created_at' => $row->created_at,
                    'attachments' => $this->normalizeMessageAttachments($row->attachment ?? null),
                ]) : collect();

            return $initial->concat($conversation);
        }

        if ($case->source_type === 'seller_activation_ticket' && Schema::hasTable('seller_activation_ticket_messages')) {
            return SellerActivationTicketMessage::query()
                ->where('seller_activation_ticket_id', $case->source_id)
                ->orderBy('id')->get()->map(fn (SellerActivationTicketMessage $message) => [
                    'id' => $message->id,
                    'sender_type' => $message->sender_type,
                    'body' => $message->body,
                    'created_at' => $message->created_at,
                    'attachments' => $this->normalizeMessageAttachments($message->attachment_full_url),
                ]);
        }

        return collect();
    }

    /**
     * Support-ticket attachments have existed in multiple storage formats over
     * time (JSON, a single filename, and an already-normalized URL array).
     * Keep the activation conversation contract stable for both the page and
     * its live JSON feed.
     */
    private function normalizeMessageAttachments(mixed $attachments): array
    {
        if ($attachments instanceof Collection) {
            $attachments = $attachments->all();
        } elseif (is_object($attachments)) {
            $attachments = (array) $attachments;
        }

        if (is_string($attachments)) {
            $decoded = json_decode($attachments, true);
            $attachments = json_last_error() === JSON_ERROR_NONE ? $decoded : [$attachments];
        }

        if (! is_array($attachments)) {
            return [];
        }

        if (isset($attachments['url']) || isset($attachments['file_name']) || isset($attachments['path'])) {
            $attachments = [$attachments];
        }

        return collect($attachments)->map(function ($attachment): ?array {
            if (is_object($attachment)) {
                $attachment = (array) $attachment;
            }

            $item = is_array($attachment) ? $attachment : ['file_name' => $attachment];
            $fileName = (string) ($item['file_name'] ?? $item['path'] ?? $item['name'] ?? '');
            $url = (string) ($item['url'] ?? $item['full_url'] ?? $item['attachment_full_url'] ?? '');

            if ($url === '' && $fileName !== '') {
                $url = filter_var($fileName, FILTER_VALIDATE_URL)
                    ? $fileName
                    : (string) dynamicStorage('storage/app/public/support-ticket/' . ltrim($fileName, '/'));
            }

            if ($url === '') {
                return null;
            }

            return [
                'url' => $url,
                'name' => (string) ($item['original_name'] ?? $item['name'] ?? basename($fileName) ?: translate('attachment')),
                'mime_type' => (string) ($item['mime_type'] ?? ''),
            ];
        })->filter()->values()->all();
    }

    private function transition(AccountActivationCase $case, string $status, string $note, int $adminId, bool $complete): AccountActivationCase
    {
        return DB::transaction(function () use ($case, $status, $note, $adminId, $complete) {
            $locked = AccountActivationCase::query()->lockForUpdate()->findOrFail($case->id);
            $this->assertEditable($locked);
            $from = $locked->status;
            $locked->update([
                'status' => $status,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => now(),
                'decision_note' => $note,
                'subject_hidden_at' => $complete ? now() : null,
                'completed_at' => $complete ? now() : null,
            ]);
            if ($complete) {
                $this->updateSubjectActivationStatus($locked, 'rejected');
                $this->completeSource($locked, false, $adminId, $note);
            } else {
                $this->updateSubjectActivationStatus($locked, 'needs_information');
            }
            $this->event($locked, 'case_'.$status, $adminId, $from, $status, $note);

            return $locked->fresh();
        });
    }

    private function assertRequiredEvidenceIsVerified(AccountActivationCase $case): void
    {
        $missingFields = $case->fields()->where('is_required', true)->where('is_verified', false)->count();
        $missingDocuments = $case->documents()->where('is_required', true)->where('verification_status', '!=', 'verified')->count();
        if ($missingFields > 0 || $missingDocuments > 0) {
            throw new DomainException('required_activation_evidence_must_be_verified');
        }
    }

    private function assertEditable(AccountActivationCase $case): void
    {
        if ($case->isCompleted()) {
            throw new DomainException('completed_activation_case_is_read_only');
        }
    }

    private function activateSeller(int $sellerId, int $adminId): void
    {
        $updates = ['status' => 'approved'];
        if (Schema::hasColumn('sellers', 'activation_status')) $updates['activation_status'] = 'active';
        if (Schema::hasColumn('sellers', 'activation_approved_at')) $updates['activation_approved_at'] = now();
        if (Schema::hasColumn('sellers', 'activation_approved_by_admin_id')) $updates['activation_approved_by_admin_id'] = $adminId;
        DB::table('sellers')->where('id', $sellerId)->update($updates);
    }

    private function activateCustomer(int $customerId, int $adminId): void
    {
        $updates = ['is_active' => 1];
        if (Schema::hasColumn('users', 'activation_status')) $updates['activation_status'] = 'active';
        if (Schema::hasColumn('users', 'activation_approved_at')) $updates['activation_approved_at'] = now();
        if (Schema::hasColumn('users', 'activation_approved_by_admin_id')) $updates['activation_approved_by_admin_id'] = $adminId;
        DB::table('users')->where('id', $customerId)->update($updates);
    }

    private function updateSubjectActivationStatus(AccountActivationCase $case, string $status): void
    {
        $table = $case->subject_type === AccountActivationCase::SUBJECT_SELLER ? 'sellers' : 'users';
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'activation_status')) {
            DB::table($table)->where('id', $case->subject_id)->update([
                'activation_status' => $status,
                'updated_at' => now(),
            ]);
        }
    }

    private function completeSource(AccountActivationCase $case, bool $approved, int $adminId, ?string $note): void
    {
        if ($case->source_type === 'support_ticket' && Schema::hasTable('support_tickets')) {
            $updates = ['status' => 'close', 'updated_at' => now()];
            if (Schema::hasColumn('support_tickets', 'hidden_from_subject_at')) {
                $updates['hidden_from_subject_at'] = now();
            }
            foreach ([
                'review_status' => $approved ? 'approved' : 'rejected',
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => now(),
                'closed_at' => now(),
            ] as $column => $value) {
                if (Schema::hasColumn('support_tickets', $column)) $updates[$column] = $value;
            }
            DB::table('support_tickets')->where('id', $case->source_id)->update($updates);
        }

        if ($case->source_type === 'seller_activation_ticket' && Schema::hasTable('seller_activation_tickets')) {
            $updates = [
                'status' => $approved ? 'approved' : 'rejected',
                'approved_by_admin_id' => $adminId,
                'decision_note' => $note,
                'closed_at' => now(),
                'hidden_from_subject_at' => now(),
                'completed_at' => now(),
                'updated_at' => now(),
            ];
            if ($approved && Schema::hasColumn('seller_activation_tickets', 'approved_at')) $updates['approved_at'] = now();
            DB::table('seller_activation_tickets')->where('id', $case->source_id)->update($updates);
        }
    }

    private function synchronizeCustomerTickets(): int
    {
        if (! Schema::hasTable('support_tickets') || ! Schema::hasColumn('support_tickets', 'customer_id')) return 0;

        $query = DB::table('support_tickets')->whereNotNull('customer_id');
        $query->where(function ($nested) {
            if (Schema::hasColumn('support_tickets', 'purpose')) $nested->where('purpose', 'account_activation');
            if (Schema::hasColumn('support_tickets', 'type')) $nested->orWhere('type', 'account_activation');
        });

        $count = 0;
        $query->orderBy('id')->chunkById(100, function ($tickets) use (&$count) {
            foreach ($tickets as $ticket) {
                $status = $this->mapCustomerTicketStatus($ticket);
                $completed = in_array($status, [AccountActivationCase::STATUS_APPROVED, AccountActivationCase::STATUS_REJECTED], true);
                $case = AccountActivationCase::query()->firstOrNew([
                    'subject_type' => AccountActivationCase::SUBJECT_CUSTOMER,
                    'subject_id' => $ticket->customer_id,
                ]);
                $case->fill([
                    'source_type' => 'support_ticket',
                    'source_id' => $ticket->id,
                    'status' => $case->isCompleted() ? $case->status : $status,
                    'subject_hidden_at' => $case->subject_hidden_at ?: ($completed ? ($ticket->hidden_from_subject_at ?? now()) : null),
                    'completed_at' => $case->completed_at ?: ($completed ? ($ticket->reviewed_at ?? $ticket->updated_at ?? now()) : null),
                    'metadata' => ['synced_from' => 'support_ticket'],
                ])->save();
                $count++;
            }
        });

        return $count;
    }

    private function synchronizeSellerTickets(): int
    {
        if (! Schema::hasTable('seller_activation_tickets')) return 0;
        $count = 0;
        DB::table('seller_activation_tickets')->orderBy('id')->chunkById(100, function ($tickets) use (&$count) {
            foreach ($tickets as $ticket) {
                $status = $this->mapSellerTicketStatus((string) $ticket->status);
                $completed = in_array($status, [AccountActivationCase::STATUS_APPROVED, AccountActivationCase::STATUS_REJECTED], true);
                $case = AccountActivationCase::query()->firstOrNew([
                    'subject_type' => AccountActivationCase::SUBJECT_SELLER,
                    'subject_id' => $ticket->seller_id,
                ]);
                $case->fill([
                    'source_type' => 'seller_activation_ticket',
                    'source_id' => $ticket->id,
                    'status' => $case->isCompleted() ? $case->status : $status,
                    'assigned_admin_id' => $case->assigned_admin_id ?: ($ticket->assigned_admin_id ?? null),
                    'subject_hidden_at' => $case->subject_hidden_at ?: ($completed ? ($ticket->hidden_from_subject_at ?? now()) : null),
                    'completed_at' => $case->completed_at ?: ($completed ? ($ticket->completed_at ?? $ticket->updated_at ?? now()) : null),
                    'metadata' => ['synced_from' => 'seller_activation_ticket'],
                ])->save();
                $count++;
            }
        });

        return $count;
    }

    private function mapCustomerTicketStatus(object $ticket): string
    {
        $review = (string) ($ticket->review_status ?? '');
        if ($review === 'approved') return AccountActivationCase::STATUS_APPROVED;
        if ($review === 'rejected') return AccountActivationCase::STATUS_REJECTED;
        if (in_array($review, ['needs_information', 'needs_completion'], true)) return AccountActivationCase::STATUS_NEEDS_INFORMATION;

        return AccountActivationCase::STATUS_PENDING;
    }

    private function mapSellerTicketStatus(string $status): string
    {
        return match ($status) {
            'approved' => AccountActivationCase::STATUS_APPROVED,
            'rejected', 'closed' => AccountActivationCase::STATUS_REJECTED,
            'needs_information', 'needs_completion' => AccountActivationCase::STATUS_NEEDS_INFORMATION,
            'in_review', 'assigned' => AccountActivationCase::STATUS_IN_REVIEW,
            default => AccountActivationCase::STATUS_PENDING,
        };
    }

    private function event(
        AccountActivationCase $case,
        string $type,
        ?int $adminId,
        ?string $from = null,
        ?string $to = null,
        ?string $note = null,
        array $metadata = []
    ): AccountActivationCaseEvent {
        return AccountActivationCaseEvent::query()->create([
            'account_activation_case_id' => $case->id,
            'event_type' => $type,
            'admin_id' => $adminId,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
