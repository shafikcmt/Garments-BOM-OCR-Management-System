<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\MonthlyRequisitionWindow as Window;
use Illuminate\Http\Request;

/**
 * The Monthly Requisition Window screen: on/off, the days of the month the
 * window is open, and an optional custom message. For Admin and the Store.
 */
class RequisitionWindowController extends Controller
{
    public static function canManage(?\App\Models\User $user): bool
    {
        return $user !== null && $user->hasAnyRole(['admin', 'store']);
    }

    public function edit(Request $request)
    {
        abort_unless(self::canManage($request->user()), 403);

        return view('store.stock.requisitions.window', [
            'enabled' => Window::enabled(),
            'startDay' => Window::startDay(),
            'endDay' => Window::endDay(),
            'customMessage' => Window::customMessage(),
            'open' => Window::isOpen(),
            'rangeText' => Window::rangeText(),
            'defaultMessage' => Window::message(),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(self::canManage($request->user()), 403);

        $data = $request->validate([
            'enabled' => ['nullable', 'in:0,1'],
            'start_day' => ['required', 'integer', 'min:1', 'max:31'],
            'end_day' => ['required', 'integer', 'min:0', 'max:31'],
            'message' => ['nullable', 'string', 'max:300'],
        ], [
            'start_day.required' => 'Enter the day the window opens.',
        ]);

        if ((int) $data['end_day'] !== 0 && (int) $data['end_day'] < (int) $data['start_day']) {
            return back()->withInput()->withErrors(['end_day' => 'The closing day cannot be before the opening day.']);
        }

        AppSetting::put(Window::KEY_ENABLED, ($data['enabled'] ?? '0') === '1' ? '1' : '0');
        AppSetting::put(Window::KEY_START_DAY, (string) (int) $data['start_day']);
        AppSetting::put(Window::KEY_END_DAY, (string) (int) $data['end_day']);
        AppSetting::put(Window::KEY_MESSAGE, trim((string) ($data['message'] ?? '')));

        return redirect()->route('store.stock.requisitions.window.edit')
            ->with('success', 'Monthly requisition window saved.');
    }
}
