<?php

namespace App\Http\Controllers\Admin\HelpAndSupport;

use App\Contracts\Repositories\ContactRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\ContactRequest;
use App\Services\ContactService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ContactController extends BaseController
{
    /**
     * @param ContactRepositoryInterface $contactRepo
     */
    public function __construct(
        private readonly ContactRepositoryInterface         $contactRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return \Illuminate\Contracts\View\View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $contacts = $this->contactRepo->getListWhere(
            orderBy: ['id'=>'desc'],
            searchValue: $request->get('searchValue'),
            dataLimit: getWebConfig('pagination_limit')
        );
        return view('admin-views.contacts.list', compact('contacts'));
    }

    public function getListByFilter(Request $request):JsonResponse
    {
        $contacts = $this->contactRepo->getListWhere(
            orderBy: ['id'=>'desc'],
            searchValue: $request->get('searchValue'),
            filters: ['reply' => $request['status']],
            dataLimit: getWebConfig('pagination_limit')
        );
        return response()->json(
            [
                'view' => view('admin-views.contacts._table' , compact('contacts'))->render(),
                'count' => count($contacts),
            ]
        );
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $this->contactRepo->update(id:$id, data: ['seen'=>1]);
        ToastMagic::success(translate('message_checked').'.');
        return redirect()->route('admin.contact.list');
    }

    public function getView($id): View
    {
        $contact = $this->contactRepo->getFirstWhere(params: ['id'=>$id]);
        return view('admin-views.contacts.view', compact('contact'));
    }

    public function delete(Request $request): JsonResponse
    {
        $this->contactRepo->delete(params: ['id'=>$request['id']]);
        return response()->json([
            'message' => translate('Message_Deleted_successfully')
        ], 200);
    }

    public function add(ContactRequest $request, ContactService $contactService): JsonResponse
    {
        $dataArray = $contactService->getAddData(request: $request);
        $this->contactRepo->add(data: $dataArray);
        return response()->json(['success' => 'Your_Message_Send_Successfully']);
    }

    public function resetAccountPassword(Request $request, int $id, \App\Services\ContactPasswordResetService $service): RedirectResponse
    {
        $data = $request->validate([
            'account_type' => 'required|in:customer,seller',
            'password' => 'required|string|min:8|max:72|confirmed',
            'identity_verified' => 'required|accepted',
        ]);
        $service->reset(\App\Models\Contact::findOrFail($id), $data['account_type'], $data['password'], (int) auth('admin')->id());
        ToastMagic::success(translate('admin_password_reset_saved'));
        return back();
    }

    public function sendMail(Request $request, $id, ContactService $contactService): RedirectResponse
    {
        $contact = $this->contactRepo->getFirstWhere(params: ['id' => $id]);
        abort_unless($contact, 404);
        $request->merge(['subject' => translate('contact_technical_support')]);
        $request->validate(['mail_body' => 'required|string|max:5000', 'subject' => 'nullable|string|max:255']);
        \Illuminate\Support\Facades\DB::table('contact_conversation_messages')->insert([
            'contact_id' => $contact->id, 'sender' => 'admin', 'body' => $request->input('mail_body'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ToastMagic::success(translate('conversation_reply_saved'));
        $data = array('body' => $request['mail_body']);

        $emailServices_smtp = getWebConfig(name: 'mail_config');
        if ($emailServices_smtp['status'] == 0) {
            $emailServices_smtp = getWebConfig(name: 'mail_config_sendgrid');
        }

        if ($emailServices_smtp['status'] == 1) {
            try {
                $dataArray = $contactService->getMailData(request: $request, data: $data, contact: $contact, companyName: getWebConfig(name: 'company_name'));
                $this->contactRepo->update(id: $id, data: $dataArray);
                ToastMagic::success(translate('mail_sent_successfully'));
            } catch (Throwable $th) {
                ToastMagic::error(translate('This_Mail_Could_Not_be_Sent') . '.');
                ToastMagic::info(translate('please_go_to_3rd_Party') . ' > ' . translate('Mail_Config') . ',' . translate('and_check_if_you_have_enabled_and_saved_your_settings_properly'));
            }
        } else {
            ToastMagic::info(translate('conversation_email_optional_notice'));
        }
        return back();
    }
}
