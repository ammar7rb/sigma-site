<?php

namespace App\Http\Controllers\Admin\Activation;

use App\Http\Controllers\BaseController;
use App\Models\AccountActivationCase;
use App\Models\AccountActivationDocument;
use App\Models\Admin;
use App\Services\AccountActivationCenterService;
use App\Services\WorkflowFeatureService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountActivationCenterController extends BaseController
{
    public function __construct(
        private readonly AccountActivationCenterService $activationCenter,
        private readonly WorkflowFeatureService $workflowFeatures,
    )
    {
        $this->middleware(function (Request $request, $next) {
            abort_unless($this->workflowFeatures->activationCenterEnabled(), 404);

            return $next($request);
        });
    }

    public function index(?Request $request, ?string $type = null): View
    {
        $request ??= request();
        $this->activationCenter->synchronizeLegacyCases();

        $tab = $request->string('tab')->toString() ?: 'pending';
        $query = AccountActivationCase::query()->with(['customer', 'seller.shop', 'assignedAdmin', 'reviewedBy']);

        match ($tab) {
            'customers' => $query->where('subject_type', AccountActivationCase::SUBJECT_CUSTOMER)
                ->whereNotIn('status', [AccountActivationCase::STATUS_APPROVED, AccountActivationCase::STATUS_REJECTED]),
            'sellers' => $query->where('subject_type', AccountActivationCase::SUBJECT_SELLER)
                ->whereNotIn('status', [AccountActivationCase::STATUS_APPROVED, AccountActivationCase::STATUS_REJECTED]),
            'needs-information' => $query->where('status', AccountActivationCase::STATUS_NEEDS_INFORMATION),
            'completed' => $query->where('status', AccountActivationCase::STATUS_APPROVED),
            'rejected' => $query->where('status', AccountActivationCase::STATUS_REJECTED),
            default => $query->whereNotIn('status', [AccountActivationCase::STATUS_APPROVED, AccountActivationCase::STATUS_REJECTED]),
        };

        if ($request->filled('assigned_admin_id')) {
            $query->where('assigned_admin_id', $request->integer('assigned_admin_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('q')) {
            $term = trim($request->string('q')->toString());
            $plainId = preg_replace('/^[CV]/i', '', $term);
            $query->where(function ($nested) use ($term, $plainId) {
                $nested->where('id', is_numeric($plainId) ? (int) $plainId : 0)
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->where('id', is_numeric($plainId) ? (int) $plainId : 0)
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('f_name', 'like', "%{$term}%")
                        ->orWhere('l_name', 'like', "%{$term}%"))
                    ->orWhereHas('seller', fn ($seller) => $seller
                        ->where('id', is_numeric($plainId) ? (int) $plainId : 0)
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('f_name', 'like', "%{$term}%")
                        ->orWhere('l_name', 'like', "%{$term}%"));
            });
        }

        $cases = $query->latest('updated_at')->paginate((int) (getWebConfig(name: 'pagination_limit') ?: 25))->withQueryString();
        $admins = Admin::query()->where('status', 1)->orderBy('name')->get(['id', 'name']);
        $counts = [
            'pending' => AccountActivationCase::query()->whereNotIn('status', ['approved', 'rejected'])->count(),
            'customers' => AccountActivationCase::query()->where('subject_type', 'customer')->whereNotIn('status', ['approved', 'rejected'])->count(),
            'sellers' => AccountActivationCase::query()->where('subject_type', 'seller')->whereNotIn('status', ['approved', 'rejected'])->count(),
            'needs-information' => AccountActivationCase::query()->where('status', 'needs_information')->count(),
            'completed' => AccountActivationCase::query()->where('status', 'approved')->count(),
            'rejected' => AccountActivationCase::query()->where('status', 'rejected')->count(),
        ];

        return view('admin-views.activation-center.index', compact('cases', 'admins', 'counts', 'tab'));
    }

    public function show(AccountActivationCase $activationCase): View
    {
        $activationCase->load([
            'customer', 'seller.shop', 'assignedAdmin', 'reviewedBy',
            'fields.createdBy', 'fields.verifiedBy', 'documents.uploadedBy',
            'documents.verifiedBy', 'events.admin',
        ]);
        $messages = $this->activationCenter->sourceMessages($activationCase);
        $admins = Admin::query()->where('status', 1)->orderBy('name')->get(['id', 'name']);

        return view('admin-views.activation-center.show', compact('activationCase', 'messages', 'admins'));
    }

    /** Live, private feed for the activation conversation. */
    public function messages(AccountActivationCase $activationCase): JsonResponse
    {
        $messages = $this->activationCenter->sourceMessages($activationCase);

        return response()->json([
            'case_id' => $activationCase->id,
            'status' => $activationCase->status,
            'messages' => $messages->values()->map(fn ($message, $index) => [
                'id' => $message['id'] ?? $index,
                'sender_type' => $message['sender_type'] ?? 'system',
                'body' => $message['body'] ?? '',
                'created_at' => isset($message['created_at']) ? (string) $message['created_at'] : null,
                'attachments' => $message['attachments'] ?? [],
            ]),
        ]);
    }

    public function assign(Request $request, AccountActivationCase $activationCase): RedirectResponse
    {
        $data = $request->validate(['assigned_admin_id' => ['required', 'integer', 'exists:admins,id']]);
        $this->activationCenter->assign($activationCase, (int) $data['assigned_admin_id'], $this->adminId());
        ToastMagic::success(translate('activation_case_assigned_successfully'));

        return back();
    }

    public function storeField(Request $request, AccountActivationCase $activationCase): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'value' => ['nullable', 'string', 'max:5000'],
            'is_sensitive' => ['nullable', 'boolean'],
            'is_required' => ['nullable', 'boolean'],
            'is_verified' => ['nullable', 'boolean'],
        ]);
        $this->activationCenter->saveField($activationCase, $data, $this->adminId());
        ToastMagic::success(translate('activation_profile_field_saved'));

        return back();
    }

    public function storeDocument(Request $request, AccountActivationCase $activationCase): RedirectResponse
    {
        $extensions = config('account_workflows.activation_center.allowed_document_extensions', []);
        $maxSize = (int) config('account_workflows.activation_center.max_document_size_kb', 10240);
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:80'],
            'other_document_name' => [
                'nullable', 'string', 'max:120',
                Rule::requiredIf($request->input('document_type') === 'other'),
            ],
            'document_number' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
            'is_required' => ['nullable', 'boolean'],
            'is_verified' => ['nullable', 'boolean'],
            'document' => ['required', 'file', 'max:'.$maxSize, 'mimes:'.implode(',', $extensions)],
        ]);
        $this->activationCenter->storeDocument($activationCase, $data, $request->file('document'), $this->adminId());
        ToastMagic::success(translate('activation_document_saved_privately'));

        return back();
    }

    public function verifyDocument(Request $request, AccountActivationCase $activationCase, AccountActivationDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'verification_status' => ['required', Rule::in(['pending', 'verified', 'rejected'])],
        ]);
        $this->activationCenter->verifyDocument($activationCase, $document, $data['verification_status'], $this->adminId());
        ToastMagic::success(translate('activation_document_status_updated'));

        return back();
    }

    public function downloadDocument(AccountActivationCase $activationCase, AccountActivationDocument $document): StreamedResponse
    {
        abort_unless((int) $document->account_activation_case_id === (int) $activationCase->id, 404);
        abort_unless(Storage::disk($document->storage_disk)->exists($document->storage_path), 404);
        $this->activationCenter->recordDocumentAccess($activationCase, $document, $this->adminId());

        if (($document->metadata['encrypted_at_rest'] ?? false) === true) {
            $encrypted = Storage::disk($document->storage_disk)->get($document->storage_path);
            $contents = Crypt::decryptString($encrypted);

            return response()->streamDownload(
                static fn () => print($contents),
                $document->original_name,
                ['Content-Type' => $document->mime_type ?: 'application/octet-stream']
            );
        }

        return Storage::disk($document->storage_disk)->download($document->storage_path, $document->original_name);
    }

    public function reply(Request $request, AccountActivationCase $activationCase): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        try {
            $this->activationCenter->reply($activationCase, $data['message'], $this->adminId());
            if ($request->expectsJson()) {
                return $this->messages($activationCase->fresh());
            }
            ToastMagic::success(translate('activation_message_sent'));
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => translate($exception->getMessage())], 422);
            }
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }

    public function decision(Request $request, AccountActivationCase $activationCase): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'request_completion', 'reject'])],
            'note' => [Rule::requiredIf($request->input('decision') !== 'approve'), 'nullable', 'string', 'max:5000'],
        ]);

        try {
            match ($data['decision']) {
                'approve' => $this->activationCenter->approve($activationCase, $data['note'] ?? null, $this->adminId()),
                'request_completion' => $this->activationCenter->requestCompletion($activationCase, $data['note'], $this->adminId()),
                'reject' => $this->activationCenter->reject($activationCase, $data['note'], $this->adminId()),
            };
            ToastMagic::success(translate('activation_case_decision_saved'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }

    public function print(AccountActivationCase $activationCase): View
    {
        $activationCase->load([
            'customer', 'seller.shop', 'assignedAdmin', 'reviewedBy', 'fields.createdBy',
            'fields.verifiedBy', 'documents.uploadedBy', 'documents.verifiedBy', 'events.admin',
        ]);
        $messages = $this->activationCenter->sourceMessages($activationCase);

        return view('admin-views.activation-center.print', compact('activationCase', 'messages'));
    }

    private function adminId(): int
    {
        return (int) auth('admin')->id();
    }
}
