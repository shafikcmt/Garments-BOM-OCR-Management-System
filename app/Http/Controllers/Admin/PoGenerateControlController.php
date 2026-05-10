<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\SupplyChain\BookingController as SupplyChainBookingController;
use App\Models\BookingPo;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PoGenerateControlController extends SupplyChainBookingController
{
    protected function bookingRoutePrefix(): string
    {
        return 'admin.po-generate-control';
    }

    protected function canControlPo(): bool
    {
        return true;
    }

    public function index(Request $request)
    {
        $allPos = BookingPo::query()
            ->with(['excelFile', 'excelRow.cells.header', 'generatedBy', 'completedBy'])
            ->latest('id')
            ->get()
            ->map(function (BookingPo $bookingPo) {
                return $this->syncBookingPoSourceControl($bookingPo);
            });

        $stats = $this->poControlStats($allPos);
        $filtered = $this->applyControlFilters($allPos, $request);
        $bookingPos = $this->paginateControlCollection($filtered, $request, 20);

        return view('admin.po-generate-control.index', compact('bookingPos', 'stats'));
    }

    public function show(BookingPo $bookingPo)
    {
        $this->authorizeBookingPo($bookingPo);

        $bookingPo->loadMissing(['excelFile', 'excelRow.cells.header', 'generatedBy', 'completedBy']);
        $bookingPo = $this->refreshBookingPoQtyFromSource($bookingPo);
        $bookingPo = $this->syncBookingPoSourceControl($bookingPo);

        $bookingData = $this->bookingData($bookingPo);
        $instructionOptions = $this->bookingInstructionOptions();
        $deliveryDestinationOptions = $this->deliveryDestinationOptions();
        $bookingRoutePrefix = $this->bookingRoutePrefix();
        $canControlPo = $this->canControlPo();

        return view('admin.po-generate-control.show', compact(
            'bookingPo',
            'bookingData',
            'instructionOptions',
            'deliveryDestinationOptions',
            'bookingRoutePrefix',
            'canControlPo'
        ));
    }

    protected function authorizeBookingPo(BookingPo $bookingPo): void
    {
        abort_if(! auth()->user()?->hasRole('admin'), 403);
    }

    private function poControlStats(Collection $bookingPos): array
    {
        return [
            'total' => $bookingPos->count(),
            'generated' => $bookingPos->filter(fn (BookingPo $po) => $this->hasHistoryAction($po, 'generated'))->count(),
            'regenerated' => $bookingPos->filter(fn (BookingPo $po) => $po->revision_no > 0 || $this->hasHistoryAction($po, 'regenerated'))->count(),
            'changed' => $bookingPos->filter(fn (BookingPo $po) => $po->needs_regenerate || count($po->booking_data['source_change_log'] ?? []) > 0)->count(),
            'completed' => $bookingPos->filter(fn (BookingPo $po) => ($po->status ?? null) === 'completed')->count(),
        ];
    }

    private function applyControlFilters(Collection $bookingPos, Request $request): Collection
    {
        $keyword = mb_strtolower(trim((string) $request->input('q', '')));
        $state = trim((string) $request->input('state', 'all'));
        $buyer = mb_strtolower(trim((string) $request->input('buyer', '')));
        $vendor = mb_strtolower(trim((string) $request->input('vendor', '')));

        return $bookingPos
            ->filter(function (BookingPo $po) use ($keyword, $state, $buyer, $vendor) {
                $data = $po->booking_data ?: [];
                $history = collect($data['generation_history'] ?? []);
                $sourceChanges = collect($data['source_change_log'] ?? []);

                if ($keyword !== '') {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $po->po_no,
                        $po->buyer_name,
                        $po->season_name,
                        $po->ihod,
                        $po->vendor_name,
                        $po->style_name,
                        $po->item_name,
                        $data['to'] ?? null,
                        $data['supplier'] ?? null,
                    ])));

                    if (! str_contains($haystack, $keyword)) {
                        return false;
                    }
                }

                if ($buyer !== '' && ! str_contains(mb_strtolower((string) $po->buyer_name), $buyer)) {
                    return false;
                }

                if ($vendor !== '' && ! str_contains(mb_strtolower((string) $po->vendor_name), $vendor)) {
                    return false;
                }

                return match ($state) {
                    'generated' => $this->hasHistoryAction($po, 'generated'),
                    'regenerated' => $po->revision_no > 0 || $this->hasHistoryAction($po, 'regenerated'),
                    'changed' => $po->needs_regenerate || $sourceChanges->isNotEmpty(),
                    'completed' => ($po->status ?? null) === 'completed',
                    default => true,
                };
            })
            ->values();
    }

    private function paginateControlCollection(Collection $items, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input('page', 1));

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function hasHistoryAction(BookingPo $po, string $action): bool
    {
        return collect($po->booking_data['generation_history'] ?? [])
            ->contains(fn ($entry) => ($entry['action'] ?? null) === $action);
    }
}
