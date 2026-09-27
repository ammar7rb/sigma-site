<?php
namespace App\Http\Controllers\RestAPI\v3\seller\auth;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\ContactPasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class SupportConversationController extends Controller
{
    private function tokenHash(Request $request): string
    {
        $token = $request->header('X-Support-Token', '');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token), 401);
        return hash('sha256', $token);
    }

    public function create(Request $request)
    {
        $hash = $this->tokenHash($request);
        $data = $request->validate([
            'name' => 'required|string|max:100', 'email' => 'required|email|max:255', 'mobile_number' => 'required|string|max:30',
            'message' => 'nullable|string|max:5000|required_without:attachments',
            'attachments' => 'nullable|array|max:5|required_without:message',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:6144',
        ]);
        // The token identifies this conversation, never an account by email.
        // Repeated submissions with the same device token reuse its ticket.
        $contact = Contact::firstOrCreate(['mobile_support_token_hash' => $hash], collect($data)->except('attachments')->all() + ['message' => $data['message'] ?? '', 'subject' => 'طلب إعادة تعيين كلمة المرور - تاجر', 'seen' => false]);
        if ($contact->wasRecentlyCreated && $request->hasFile('attachments')) {
            $attachments = $this->storeAttachments($request);
            $message = ['contact_id' => $contact->id, 'sender' => 'requester', 'body' => '', 'created_at' => now(), 'updated_at' => now()];
            if (Schema::hasColumn('contact_conversation_messages', 'attachments')) $message['attachments'] = json_encode($attachments);
            DB::table('contact_conversation_messages')->insert($message);
        }
        return $this->show($request);
    }

    public function show(Request $request)
    {
        $contact = Contact::where('mobile_support_token_hash', $this->tokenHash($request))->firstOrFail();
        $messages = DB::table('contact_conversation_messages')->where('contact_id', $contact->id)->orderBy('id')->get()->map(fn ($message) => [
            'id' => $message->id, 'sender' => $message->sender === 'requester' ? 'requester' : 'admin',
            'body' => ContactPasswordResetService::displayBody($message),
            'attachments' => collect(json_decode($message->attachments ?? '[]', true))->map(fn ($file) => [
                'name' => $file['name'], 'url' => Storage::disk('public')->url($file['path']),
            ])->values(),
            'created_at' => $message->created_at,
        ]);
        return response()->json(['id' => $contact->id, 'initial_message' => $contact->message, 'messages' => $messages])->header('Cache-Control', 'private, no-store');
    }

    public function reply(Request $request)
    {
        $contact = Contact::where('mobile_support_token_hash', $this->tokenHash($request))->firstOrFail();
        $data = $request->validate([
            'message' => 'nullable|string|max:5000|required_without:attachments',
            'attachments' => 'nullable|array|max:5|required_without:message',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:6144',
        ]);
        $attachments = $this->storeAttachments($request);
        DB::transaction(function () use ($contact, $data, $attachments) {
            $message = ['contact_id' => $contact->id, 'sender' => 'requester', 'body' => $data['message'] ?? '', 'created_at' => now(), 'updated_at' => now()];
            if (Schema::hasColumn('contact_conversation_messages', 'attachments')) $message['attachments'] = $attachments ? json_encode($attachments) : null;
            DB::table('contact_conversation_messages')->insert($message);
            $contact->update(['seen' => false]);
        });
        return $this->show($request);
    }

    private function storeAttachments(Request $request): array
    {
        return collect($request->file('attachments', []))->map(fn ($file) => [
            'name' => $file->getClientOriginalName(),
            'path' => $file->store('support-conversations', 'public'),
        ])->values()->all();
    }
}
