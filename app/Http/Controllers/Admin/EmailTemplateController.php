<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    /**
     * Editable email templates, keyed by type. Add new templates here to
     * expose them on the admin screen without further code changes.
     *
     * @return array<string, string>
     */
    protected function templateNames(): array
    {
        return [
            'pra' => 'Payment Request Approval (PRA)',
            'po_booking' => 'PO Booking to Supplier',
        ];
    }

    public function edit()
    {
        return view('admin.email-templates.edit', [
            'praTemplate' => EmailTemplate::forType('pra'),
            'poBookingTemplate' => EmailTemplate::forType('po_booking'),
            'praPlaceholders' => $this->praPlaceholderHints(),
            'poBookingPlaceholders' => $this->poBookingPlaceholderHints(),
            'canManageRecipients' => $this->canManageRecipients(),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', array_keys($this->templateNames()))],
            'subject' => ['required', 'string', 'max:255'],
            'default_to' => ['nullable', 'string', 'max:1000'],
            'default_cc' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $template = EmailTemplate::firstOrNew(['type' => $validated['type']]);

        $template->fill([
            'name' => $this->templateNames()[$validated['type']],
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'updated_by' => auth()->id(),
        ]);

        // Wording is one thing; who the mail goes to is another. An
        // email.templates.edit holder saves subject and body, and the standing
        // recipient list keeps whatever the admin last set — the posted values
        // are dropped rather than rejected, so a Supply Chain save of a
        // legitimate body change does not bounce on fields the form has
        // disabled anyway.
        if ($this->canManageRecipients()) {
            $template->default_to = $validated['default_to'] ?? null;
            $template->default_cc = $validated['default_cc'] ?? null;
        }

        // Only on the first save. An existing row's author is whoever created
        // it — or null for the two rows that predate this column, whose author
        // is genuinely unknown and is not going to be invented here.
        if (! $template->exists) {
            $template->created_by = auth()->id();
        }

        $template->save();

        return redirect()
            ->route('admin.email-templates.edit')
            ->with('success', $this->templateNames()[$validated['type']] . ' email template updated successfully.');
    }

    /**
     * May the current user change the standing To / Cc lists?
     *
     * Admin only, and deliberately not a permission of its own: editing the
     * recipient list silently redirects future mail, which is the one part of
     * these screens nobody has asked to delegate.
     */
    protected function canManageRecipients(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    /**
     * Placeholders supported by the PRA template.
     *
     * @return array<string, string>
     */
    protected function praPlaceholderHints(): array
    {
        return [
            '{{pr_number}}' => 'PRA / PR number',
            '{{buyer}}' => 'Buyer name(s)',
            '{{season}}' => 'Season',
            '{{supplier}}' => 'Vendor / supplier name(s)',
            '{{payment_require_date}}' => 'Payment require date',
            '{{total_amount}}' => 'Total PI amount',
            '{{date}}' => 'PRA created date',
            '{{company_name}}' => 'Company name',
        ];
    }

    /**
     * Placeholders supported by the PO Booking template.
     *
     * @return array<string, string>
     */
    protected function poBookingPlaceholderHints(): array
    {
        return [
            '{{supplier_name}}' => 'Supplier / vendor name',
            '{{po_number}}' => 'PO / Booking number',
            '{{buyer}}' => 'Buyer name',
            '{{style_no}}' => 'Order / style number',
            '{{season}}' => 'Season',
            '{{date}}' => 'Booking generated date',
            '{{sender_name}}' => 'Sender (supply-chain user) name',
            '{{company_name}}' => 'Company name',
        ];
    }
}
