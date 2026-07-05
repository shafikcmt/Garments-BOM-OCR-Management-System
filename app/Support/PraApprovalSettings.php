<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Admin-configurable settings for the PRA digital approval notifications.
 * Dashboard (in-app) notifications are always on; only the email channel can
 * be toggled here. Stored in the app_settings key/value table.
 */
class PraApprovalSettings
{
    public const KEY_MAIL_ENABLED = 'pra_approval_mail_enabled';

    /**
     * Whether approval-request / result emails should be sent. Defaults to on
     * so the feature works out of the box after install.
     */
    public static function mailEnabled(): bool
    {
        return (bool) ((int) AppSetting::get(self::KEY_MAIL_ENABLED, 1));
    }
}
