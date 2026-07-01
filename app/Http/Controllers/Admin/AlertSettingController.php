<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\PiAlertSettings;
use Illuminate\Http\Request;

class AlertSettingController extends Controller
{
    public function edit()
    {
        return view('admin.alert-settings.edit', [
            'departmentOptions' => PiAlertSettings::departmentOptions(),
            'piAlertDays' => PiAlertSettings::days(),
            'selectedDepartments' => PiAlertSettings::departments(),
            'mailEnabled' => PiAlertSettings::mailEnabled(),
            'mailRecipientsMode' => PiAlertSettings::mailRecipientsMode(),
            'mailEmails' => PiAlertSettings::mailEmailsRaw(),
        ]);
    }

    public function update(Request $request)
    {
        $allowedDepartments = array_keys(PiAlertSettings::departmentOptions());

        $validated = $request->validate([
            'pi_alert_days' => ['required', 'integer', 'min:1', 'max:365'],
            'pi_alert_departments' => ['nullable', 'array'],
            'pi_alert_departments.*' => ['string', 'in:' . implode(',', $allowedDepartments)],
            'pi_alert_mail_enabled' => ['nullable', 'boolean'],
            'pi_alert_mail_recipients' => ['required', 'in:department_users,specific'],
            'pi_alert_mail_emails' => ['nullable', 'string', 'max:2000'],
        ]);

        $departments = array_values(array_intersect(
            $validated['pi_alert_departments'] ?? [],
            $allowedDepartments
        ));

        AppSetting::put(PiAlertSettings::KEY_DAYS, (string) $validated['pi_alert_days']);
        AppSetting::put(PiAlertSettings::KEY_DEPARTMENTS, json_encode($departments));
        AppSetting::put(PiAlertSettings::KEY_MAIL_ENABLED, $request->boolean('pi_alert_mail_enabled') ? '1' : '0');
        AppSetting::put(PiAlertSettings::KEY_MAIL_RECIPIENTS, $validated['pi_alert_mail_recipients']);
        AppSetting::put(PiAlertSettings::KEY_MAIL_EMAILS, $validated['pi_alert_mail_emails'] ?? null);

        return redirect()
            ->route('admin.alert-settings.edit')
            ->with('success', 'Alert settings updated successfully.');
    }
}
