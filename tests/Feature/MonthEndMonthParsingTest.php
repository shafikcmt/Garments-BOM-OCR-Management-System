<?php

use App\Exports\GeneralStockReportExport;
use App\Services\GeneralStockReportService;
use App\Services\IssueReportService;
use App\Services\MonthlyRequisitionReportService;
use App\Services\ReceivingReportService;
use Illuminate\Support\Carbon;

/**
 * A "YYYY-MM" month filter must mean that month on every day of the year.
 *
 * Carbon::createFromFormat('Y-m', …) fills the missing day from TODAY, so on
 * the 31st "2026-02" became 31 February — rolled over to 3 March — and
 * startOfMonth() then pinned it to March. Every report below opened the
 * wrong month on the 29th, 30th and 31st. '!Y-m' resets the missing fields.
 *
 * Time is frozen on the 31st (and the 30th) and each resolver is asked for a
 * short month: February, and a 30-day month.
 */
afterEach(fn () => Carbon::setTestNow());

dataset('month end', [
    'Feb on 31 Jan' => ['2026-01-31', '2026-02', '2026-02-01'],
    'Feb on 30 Mar' => ['2026-03-30', '2026-02', '2026-02-01'],
    'leap Feb on 31 Aug' => ['2028-08-31', '2028-02', '2028-02-01'],
    'Apr (30 days) on 31 Mar' => ['2026-03-31', '2026-04', '2026-04-01'],
    'Sep (30 days) on 31 Oct' => ['2026-10-31', '2026-09', '2026-09-01'],
]);

it('resolves the General Stock report month', function (string $today, string $month, string $expected) {
    Carbon::setTestNow($today);

    expect(app(GeneralStockReportService::class)->resolveMonth($month)->toDateString())->toBe($expected);
})->with('month end');

it('resolves the Issue report month', function (string $today, string $month, string $expected) {
    Carbon::setTestNow($today);

    expect(app(IssueReportService::class)->resolveMonth($month)?->toDateString())->toBe($expected);
})->with('month end');

it('resolves the Monthly Requisition report month', function (string $today, string $month, string $expected) {
    Carbon::setTestNow($today);

    expect(app(MonthlyRequisitionReportService::class)->resolveMonth($month)->toDateString())->toBe($expected);
})->with('month end');

it('resolves the Receiving report month', function (string $today, string $month, string $expected) {
    Carbon::setTestNow($today);

    expect(app(ReceivingReportService::class)->resolveMonth($month)?->toDateString())->toBe($expected);
})->with('month end');

it('names the General Stock export sheet after the right month', function (string $today, string $month, string $expected) {
    Carbon::setTestNow($today);

    expect((new GeneralStockReportExport(['month' => $month]))->title())
        ->toBe('Stock '.Carbon::parse($expected)->format('M-y'));
})->with('month end');

it('still falls back safely on a malformed month', function () {
    Carbon::setTestNow('2026-01-31');

    expect(app(GeneralStockReportService::class)->resolveMonth('not-a-month')->toDateString())->toBe('2026-01-01')
        ->and(app(MonthlyRequisitionReportService::class)->resolveMonth('Feb 2026')->toDateString())->toBe('2026-01-01')
        ->and(app(IssueReportService::class)->resolveMonth('garbage'))->toBeNull()
        ->and(app(ReceivingReportService::class)->resolveMonth(''))->toBeNull();
});
