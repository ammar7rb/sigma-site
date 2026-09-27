<?php

namespace App\Http\Controllers\Admin\HelpAndSupport;

use App\Contracts\Repositories\SupportTicketConvRepositoryInterface;
use App\Contracts\Repositories\SupportTicketRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\SupportTicketRequest;
use App\Repositories\SupportTicketRepository;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use App\Models\SupportTicket;
use App\Services\CustomerActivationService;

class SupportTicketController extends BaseController
{
    /**
     * @param SupportTicketRepository $supportTicketRepo
     */
    public function __construct(
        private readonly SupportTicketRepositoryInterface $supportTicketRepo,
        private readonly SupportTicketConvRepositoryInterface $supportTicketConvRepo,
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
        $tickets = $this->supportTicketRepo->getListWhere(
            orderBy: ['updated_at' => 'desc'],
            searchValue: $request->get('searchValue'),
            filters: ['priority' => $request['priority'], 'status' => $request['status'], 'purpose' => $request['purpose'], 'archived' => $request->input('archived', 'active')],
            relations: ['customer'],
            dataLimit: getWebConfig('pagination_limit')
        );
        return view('admin-views.support-ticket.view', compact('tickets'));
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $ticket = $this->supportTicketRepo->getFirstWhere(params:['id' => $request['id']]);
        $status = $ticket['status'] == 'open' ? 'close' : 'open';
        $this->supportTicketRepo->update(id: $ticket['id'], data: [
            'status' => $status,
            'closed_at' => $status === 'close' ? now() : null,
            'archived_at' => $status === 'open' ? null : $ticket->archived_at,
        ]);

        return response()->json(['message' => translate('Support_ticket_status_updated')]);
    }

    public function getView($id): View
    {
        $supportTicket = $this->supportTicketRepo->getListWhere(filters: ['id'=>$id], relations: ['conversations'], dataLimit: 'all');
        return view('admin-views.support-ticket.singleView', compact('supportTicket'));
    }

    /** Polling endpoint used by the administrative support workspace. */
    public function messages(int $id): JsonResponse
    {
        $ticket = SupportTicket::query()->with(['conversations' => fn ($query) => $query->orderBy('id')])->findOrFail($id);

        return response()->json([
            'ticket_id' => $ticket->id,
            'status' => $ticket->status,
            'messages' => $ticket->conversations->map(fn ($conversation) => [
                'id' => $conversation->id,
                'sender_type' => $conversation->admin_id ? 'admin' : 'customer',
                'body' => $conversation->admin_message ?: $conversation->customer_message,
                'created_at' => optional($conversation->created_at)->format('Y-m-d H:i'),
            ])->values(),
        ]);
    }

    public function print(int $id): View
    {
        $ticket = SupportTicket::query()
            ->with(['customer', 'conversations.adminInfo'])
            ->findOrFail($id);

        return view('admin-views.support-ticket.print', compact('ticket'));
    }

    public function reply(SupportTicketRequest $request, SupportTicketService $supportTicketService): RedirectResponse|JsonResponse
    {
        $ticket = $this->supportTicketRepo->getFirstWhere(params: ['id' => $request['id']]);
        if (!$ticket || $ticket->archived_at) {
            ToastMagic::warning(translate('Invalid_ticket'));
            return back();
        }
        if ($request['media'] == null && $request['replay'] == null) {
            ToastMagic::warning(translate('type_something').'!');
            return back();
        }
        if ($request['replay'] && mb_strlen($request['replay']) > 189) {
            ToastMagic::warning(translate('you_cannot_send_more_than_189_characters_text_!'));
            return back();
        }
        $dataArray = $supportTicketService->getAddData(request: $request);
        $this->supportTicketConvRepo->add(data: $dataArray);
        $this->supportTicketRepo->update(id: $request['id'], data: ['status' => 'open', 'closed_at' => null, 'archived_at' => null]);
        if ($request->expectsJson()) {
            return $this->messages((int) $request['id']);
        }
        return back();
    }

    public function activationDecision(Request $request, int $id, CustomerActivationService $service): RedirectResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject,request_completion',
            'note' => 'nullable|string|max:1000',
        ]);

        $service->decide(
            SupportTicket::query()->with('customer')->findOrFail($id),
            $data['decision'],
            $data['note'] ?? null,
            (int) auth('admin')->id(),
        );

        ToastMagic::success(translate('customer_activation_decision_saved'));
        return back();
    }

}
