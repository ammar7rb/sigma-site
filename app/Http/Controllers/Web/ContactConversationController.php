<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactConversationController extends Controller
{
    private function ownedContact(Request $request, int $contact): Contact
    {
        // The session that submitted the request owns this conversation.
        // Knowing another account's email or a contact ID grants no access.
        abort_unless(in_array($contact, $request->session()->get('contact_conversation_ids', []), true), 404);
        return Contact::findOrFail($contact);
    }

    public function show(Request $request, int $contact)
    {
        $contact = $this->ownedContact($request, $contact);
        $messages = DB::table('contact_conversation_messages')->where('contact_id', $contact->id)->orderBy('id')->get();
        $messages->each(function ($message) {
            $message->body = \App\Services\ContactPasswordResetService::displayBody($message);
            if ($message->sender === 'admin_password') $message->sender = 'admin';
        });
        if ($request->expectsJson()) return response()->json(['messages' => $messages])->header('Cache-Control', 'private, no-store');
        return response()->view('web-views.contact-conversation', compact('contact', 'messages'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function reply(Request $request, int $contact)
    {
        $contact = $this->ownedContact($request, $contact);
        $data = $request->validate(['message' => 'required|string|max:5000']);
        DB::transaction(function () use ($contact, $data) {
            DB::table('contact_conversation_messages')->insert([
                'contact_id' => $contact->id, 'sender' => 'requester', 'body' => $data['message'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $contact->update(['seen' => false]);
        });
        return redirect()->route('contact.conversation', $contact->id);
    }
}
