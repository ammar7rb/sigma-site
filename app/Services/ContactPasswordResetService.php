<?php
namespace App\Services;

use App\Models\Contact;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactPasswordResetService
{
    public function reset(Contact $contact, string $type, string $password, int $adminId): void
    {
        if (!in_array($type, ['customer', 'seller'], true)) abort(422);
        DB::transaction(function () use ($contact, $type, $password, $adminId) {
            $model = $type === 'seller' ? Seller::class : User::class;
            $account = $model::where('email', trim((string) $contact->email))->lockForUpdate()->first();
            if (!$account) throw ValidationException::withMessages(['account_type' => translate('reset_contact_account_not_found')]);
            $account->password = Hash::make($password);
            if ($type === 'customer') $account->remember_token = Str::random(60);
            $account->save();
            DB::table('contact_conversation_messages')->insert([
                'contact_id' => $contact->id, 'sender' => 'admin_password',
                'body' => Crypt::encryptString(json_encode([
                    'password' => $password, 'email' => $account->email, 'account_type' => $type,
                    'account_id' => $account->id, 'admin_id' => $adminId,
                ], JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public static function displayBody(object $message): string
    {
        if ($message->sender !== 'admin_password') return $message->body;
        $data = json_decode(Crypt::decryptString($message->body), true, 512, JSON_THROW_ON_ERROR);
        return translate('admin_password_reset_message') . "\n" . $data['email'] . "\n" . $data['password'];
    }
}
