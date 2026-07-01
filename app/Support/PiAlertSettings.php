<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Central access point for the admin-configurable PI Missing Alert settings.
 * All keys are stored in the app_settings table.
 */
class PiAlertSettings
{
    public const KEY_DAYS = 'pi_alert_days';
    public const KEY_DEPARTMENTS = 'pi_alert_departments';
    public const KEY_MAIL_ENABLED = 'pi_alert_mail_enabled';
    public const KEY_MAIL_RECIPIENTS = 'pi_alert_mail_recipients';
    public const KEY_MAIL_EMAILS = 'pi_alert_mail_emails';

    public const DEFAULT_DAYS = 3;

    /**
     * Department/role options shown in the admin panel.
     * Map of role name (as stored in roles table) => display label.
     */
    public static function departmentOptions(): array
    {
        return [
            'merchant' => 'Merchandising',
            'supply_chain' => 'Supply Chain',
            'commercial' => 'Commercial',
            'store' => 'Store',
            'account' => 'Accounts',
            'admin' => 'Management / Admin',
        ];
    }

    public static function days(): int
    {
        return max(1, (int) AppSetting::get(self::KEY_DAYS, self::DEFAULT_DAYS));
    }

    /**
     * @return array<int, string> selected role names
     */
    public static function departments(): array
    {
        $raw = AppSetting::get(self::KEY_DEPARTMENTS, null);

        if (is_array($raw)) {
            $value = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        $allowed = array_keys(self::departmentOptions());

        return array_values(array_intersect($value, $allowed));
    }

    public static function mailEnabled(): bool
    {
        return (bool) ((int) AppSetting::get(self::KEY_MAIL_ENABLED, 0));
    }

    /**
     * 'department_users' or 'specific'
     */
    public static function mailRecipientsMode(): string
    {
        $mode = (string) AppSetting::get(self::KEY_MAIL_RECIPIENTS, 'department_users');

        return in_array($mode, ['department_users', 'specific'], true) ? $mode : 'department_users';
    }

    public static function mailEmailsRaw(): string
    {
        return (string) AppSetting::get(self::KEY_MAIL_EMAILS, '');
    }

    /**
     * Parsed list of specific email addresses.
     *
     * @return array<int, string>
     */
    public static function mailEmails(): array
    {
        $raw = self::mailEmailsRaw();

        if (trim($raw) === '') {
            return [];
        }

        return collect(preg_split('/[,;\r\n]+/', $raw))
            ->map(fn ($email) => trim($email))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }
}
