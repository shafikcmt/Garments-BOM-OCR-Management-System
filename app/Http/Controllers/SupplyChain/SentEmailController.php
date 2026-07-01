<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\Request;

class SentEmailController extends Controller
{
    /**
     * Consolidated list of all sent emails (PO Booking + PRA) with light
     * filters. Reuses the shared email-history partial for the table/actions.
     */
    public function index(Request $request)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $filters = [
            'type' => trim((string) $request->input('type')),
            'status' => trim((string) $request->input('status')),
            'date_from' => trim((string) $request->input('date_from')),
            'date_to' => trim((string) $request->input('date_to')),
            'search' => trim((string) $request->input('search')),
        ];

        $query = EmailLog::query()
            ->with(['sentBy', 'paymentRequest', 'bookingPo'])
            ->latest('id');

        if ($filters['type'] === 'pra') {
            $query->whereNotNull('payment_request_id');
        } elseif ($filters['type'] === 'po_booking') {
            $query->whereNotNull('booking_po_id');
        }

        if (in_array($filters['status'], ['sent', 'failed'], true)) {
            $query->where('status', $filters['status']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if ($filters['search'] !== '') {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', $term)
                    ->orWhere('recipients', 'like', $term)
                    ->orWhere('cc', 'like', $term)
                    ->orWhereHas('paymentRequest', fn ($sub) => $sub->where('request_no', 'like', $term))
                    ->orWhereHas('bookingPo', fn ($sub) => $sub->where('po_no', 'like', $term));
            });
        }

        $emailLogs = $query->paginate(25)->withQueryString();

        return view('supply-chain.sent-emails.index', compact('emailLogs', 'filters'));
    }
}
