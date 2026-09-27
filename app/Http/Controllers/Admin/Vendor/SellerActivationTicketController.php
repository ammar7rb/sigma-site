<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Http\Controllers\Admin\Activation\AccountActivationCenterController;
use App\Models\AccountActivationCase;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Compatibility facade for integrations that still reference the former
 * seller activation-ticket controller. The activation center is now the
 * single source of truth for seller and customer activation conversations.
 */
class SellerActivationTicketController extends AccountActivationCenterController
{
    /**
     * Keep the legacy method contract while delegating to the unified center.
     * The unified reply request accepts message and attachments.* payloads.
     */
    public function reply(Request $request, AccountActivationCase $activationCase): RedirectResponse|JsonResponse
    {
        // attachments.* is handled by the activation-center message contract.
        return parent::reply($request, $activationCase);
    }

    public function print(AccountActivationCase $activationCase): View
    {
        return parent::print($activationCase);
    }
}
