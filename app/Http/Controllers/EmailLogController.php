<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;

class EmailLogController extends Controller
{
    /**
     * Soft-delete (hide) a sent-email log entry. This only removes the record
     * from the history list for audit tidiness — it does not undo the email
     * that was already delivered. Allowed for admins or the original sender.
     */
    public function destroy(EmailLog $emailLog)
    {
        abort_unless($emailLog->canBeDeletedBy(auth()->user()), 403);

        $emailLog->delete();

        return back()->with('success', 'Email record removed from the history.');
    }
}
