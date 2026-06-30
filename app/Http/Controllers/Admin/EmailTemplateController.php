<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function edit()
    {
        $template = EmailTemplate::forType('pra');

        return view('admin.email-templates.edit', [
            'template' => $template,
            'placeholders' => $this->placeholderHints(),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'default_to' => ['nullable', 'string', 'max:1000'],
            'default_cc' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        EmailTemplate::updateOrCreate(
            ['type' => 'pra'],
            [
                'name' => 'Payment Request Approval (PRA)',
                'subject' => $validated['subject'],
                'default_to' => $validated['default_to'] ?? null,
                'default_cc' => $validated['default_cc'] ?? null,
                'body' => $validated['body'],
            ]
        );

        return redirect()
            ->route('admin.email-templates.edit')
            ->with('success', 'Email template updated successfully.');
    }

    /**
     * Human-readable list of supported placeholders.
     *
     * @return array<string, string>
     */
    protected function placeholderHints(): array
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
}
