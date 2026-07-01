<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExcelHeader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class HeaderController extends Controller
{
    public function index()
    {
        // Existing duplicate/gap position thakle list open korlei sequence fix hobe.
        $this->normalizeHeaderPositions();

        $headers = ExcelHeader::with('ownerRole')->orderBy('position')->get();

        return view('admin.headers.index', compact('headers'));
    }

    public function create()
    {
        $this->normalizeHeaderPositions();

        $roles = Role::orderBy('name')->get();
        $nextPosition = ((int) ExcelHeader::max('position')) + 1;
        $formulaOptions = $this->formulaOptions();
        $formulaMetaExamples = $this->formulaMetaExamples();

        return view('admin.headers.create', compact('roles', 'nextPosition', 'formulaOptions', 'formulaMetaExamples'));
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);

        DB::transaction(function () use ($data) {
            // Age existing position 1,2,3... kore nilam.
            $this->resequencePositions();

            $maxPosition = (int) ExcelHeader::max('position');
            $insertPosition = min(max(1, (int) $data['position']), $maxPosition + 1);

            // J position e new header boshbe, oi position theke porer sob 1 step niche shift hobe.
            ExcelHeader::where('position', '>=', $insertPosition)->increment('position');

            $data['position'] = $insertPosition;
            ExcelHeader::create($data);
        });

        return redirect()
            ->route('admin.headers.index')
            ->with('success', 'Header created successfully.');
    }

    public function edit(ExcelHeader $header)
    {
        $this->normalizeHeaderPositions();
        $header->refresh();

        $roles = Role::orderBy('name')->get();
        $formulaOptions = $this->formulaOptions();
        $formulaMetaExamples = $this->formulaMetaExamples();

        return view('admin.headers.edit', compact('header', 'roles', 'formulaOptions', 'formulaMetaExamples'));
    }

    public function update(Request $request, ExcelHeader $header)
    {
        $data = $this->validatedData($request, $header);

        DB::transaction(function () use ($data, $header) {
            $this->resequencePositions();
            $header->refresh();

            $oldPosition = (int) $header->position;
            $maxPosition = (int) ExcelHeader::max('position');
            $newPosition = min(max(1, (int) $data['position']), $maxPosition);

            if ($newPosition < $oldPosition) {
                // Example: 5 -> 2, tahole 2,3,4 position ek step kore niche jabe.
                ExcelHeader::where('id', '!=', $header->id)
                    ->whereBetween('position', [$newPosition, $oldPosition - 1])
                    ->increment('position');
            } elseif ($newPosition > $oldPosition) {
                // Example: 2 -> 5, tahole 3,4,5 position ek step kore upore ashbe.
                ExcelHeader::where('id', '!=', $header->id)
                    ->whereBetween('position', [$oldPosition + 1, $newPosition])
                    ->decrement('position');
            }

            $data['position'] = $newPosition;
            $header->update($data);

            // Final safety: kono unexpected gap/duplicate thakle abar clean kore dibe.
            $this->resequencePositions();
        });

        return redirect()
            ->route('admin.headers.index')
            ->with('success', 'Header updated successfully.');
    }

    public function destroy(ExcelHeader $header)
    {
        DB::transaction(function () use ($header) {
            $this->resequencePositions();
            $header->refresh();

            $deletedPosition = (int) $header->position;
            $header->delete();

            // Deleted position er porer sob header ek step kore upore ashbe.
            ExcelHeader::where('position', '>', $deletedPosition)->decrement('position');

            // Final safety: sequence 1,2,3... ensure.
            $this->resequencePositions();
        });

        return redirect()
            ->route('admin.headers.index')
            ->with('success', 'Header deleted successfully. Position sequence updated.');
    }

    private function validatedData(Request $request, ?ExcelHeader $header = null): array
    {
        $formulaKeys = array_keys($this->formulaOptions());

        $data = $request->validate([
            'header_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('excel_headers', 'header_name')->ignore($header?->id),
            ],
            'header_key' => [
                'required',
                'string',
                'max:255',
                Rule::unique('excel_headers', 'header_key')->ignore($header?->id),
            ],
            'owner_role_id' => ['required', 'exists:roles,id'],
            'position' => ['required', 'integer', 'min:1'],
            'field_type' => ['required', 'in:text,number,date'],
            'value_mode' => ['required', 'in:input,formula,conditional'],
            'formula_key' => [
                Rule::requiredIf(fn () => in_array($request->input('value_mode'), ['formula', 'conditional'], true)),
                'nullable',
                'string',
                'max:100',
                Rule::in($formulaKeys),
            ],
            'formula_meta' => [
                'nullable',
                'json',
            ],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'can_view_all' => ['nullable', 'boolean'],
            'can_edit_owner_only' => ['nullable', 'boolean'],
        ], [
            'header_name.required' => 'Header name is required.',
            'header_name.unique' => 'Header name already exists. Please use a unique name.',
            'header_key.required' => 'Header key is required.',
            'header_key.unique' => 'Header key already exists. Please use a unique key.',
            'owner_role_id.required' => 'Owner role is required.',
            'owner_role_id.exists' => 'Selected owner role is invalid.',
            'position.required' => 'Position is required.',
            'position.integer' => 'Position must be a valid number.',
            'field_type.required' => 'Field type is required.',
            'value_mode.required' => 'Value mode is required.',
            'formula_key.required' => 'Formula / rule key is required for formula or conditional headers.',
            'formula_key.in' => 'Selected formula / rule key is invalid.',
            'formula_meta.json' => 'Formula meta must be valid JSON.',
        ]);

        $merchantRoleId = (int) Role::where('name', 'merchant')->value('id');
        $isInputMode = $data['value_mode'] === 'input';
        $isMerchantOwner = (int) $data['owner_role_id'] === $merchantRoleId;

        $data['is_required'] = $request->boolean('is_required');
        $data['is_active'] = $request->boolean('is_active');
        $data['can_view_all'] = $request->boolean('can_view_all');
        $data['can_edit_owner_only'] = $request->boolean('can_edit_owner_only');

        if ($isInputMode) {
            $data['formula_key'] = null;
            $data['formula_meta'] = null;
        } else {
            $formulaKey = $data['formula_key'] ?? null;
            $formulaMeta = $data['formula_meta'] ?? null;

            if (($formulaMeta === null || trim((string) $formulaMeta) === '') && $formulaKey) {
                $formulaMeta = json_encode($this->formulaMetaExamples()[$formulaKey] ?? [], JSON_UNESCAPED_SLASHES);
            }

            $data['formula_meta'] = $formulaMeta ? json_decode($formulaMeta, true) : null;
        }

        // Shudhu merchant-owned input header uploadable hobe.
        // Project e kon column ache seta check kore set kora hocche, karon kichu DB te
        // merchant_can_upload, kichu DB te can_merchant_upload naming thakte pare.
        $merchantCanUpload = $isMerchantOwner && $isInputMode;

        if (Schema::hasColumn('excel_headers', 'merchant_can_upload')) {
            $data['merchant_can_upload'] = $merchantCanUpload;
        }

        if (Schema::hasColumn('excel_headers', 'can_merchant_upload')) {
            $data['can_merchant_upload'] = $merchantCanUpload;
        }

        return $data;
    }

    private function formulaOptions(): array
    {
        return [
            'shipment_month' => 'Shipment Month',
            'pcd_required' => 'PCD Required',
            'order_to_be_placed_by' => 'Order to be placed by',
            'consumption_incl_yy' => 'Consumption including YY',
            'materials_to_be_ordered' => 'Materials to be Ordered',
            'short_excess_ordered' => '(Short)/Excess Ordered',
            'material_order_status' => 'Material Order Status',
            'pi_amount' => 'PI Amount',
            'committed_inhouse' => 'Committed Inhouse',
            'pcd_as_per_committed_inhouse' => 'PCD as per Committed Inhouse',
            'liability_based_on_receiving' => 'Liability Based On Receiving',
            'buyer_liability' => 'Buyer Liability',
            'buyer_liability_value' => 'Buyer Liability Value',
            'final_status' => 'Final Status',
        ];
    }

    private function formulaMetaExamples(): array
    {
        return [
            'shipment_month' => [
                'source_header_key' => 'contract_shipment_date',
            ],
            'pcd_required' => [
                'source_header_key' => 'contract_shipment_date',
                'subtract_days' => 45,
                'format' => 'Y-m-d',
            ],
            'order_to_be_placed_by' => [
                'source_header_key' => 'pcd_required',
                'subtract_days' => 70,
                'format' => 'Y-m-d',
            ],
            'consumption_incl_yy' => [
                'formula' => 'booking_consumption_from_cad * (1 + wastage_for_ordering_percent)',
                'source_header_keys' => [
                    'booking_consumption_from_cad',
                    'wastage_for_ordering_percent',
                ],
            ],
            'materials_to_be_ordered' => [
                'formula' => 'consumption_based_on_which_materials_order_including_yy * customer_contract_quantity',
                'source_header_keys' => [
                    'consumption_based_on_which_materials_order_including_yy',
                    'customer_contract_quantity',
                ],
            ],
            'short_excess_ordered' => [
                'formula' => 'materials_ordered - materials_to_be_ordered',
                'source_header_keys' => [
                    'materials_ordered',
                    'materials_to_be_ordered',
                ],
            ],
            'material_order_status' => [
                'source_header_key' => 'short_excess_ordered',
            ],
            'pi_amount' => [
                'formula' => 'pi_rate * materials_to_be_ordered',
                'source_header_keys' => [
                    'pi_rate',
                    'materials_to_be_ordered',
                ],
            ],
            'committed_inhouse' => [
                'formula' => 'committed_eta + 7 days',
                'source_header_key' => 'committed_eta',
                'add_days' => 7,
            ],
            'pcd_as_per_committed_inhouse' => [
                'formula' => 'committed_inhouse + 2 days',
                'source_header_key' => 'committed_inhouse',
                'add_days' => 2,
            ],
            'liability_based_on_receiving' => [
                'formula' => 'receipt_qty - materials_to_be_ordered',
                'source_header_keys' => [
                    'receipt_qty',
                    'materials_to_be_ordered',
                ],
            ],
            'buyer_liability' => [
                'formula' => '(bom_quantity * consumption_based_on_which_materials_order_including_yy) - (gmts_order_qty * costing_yy_in_sms)',
                'source_header_keys' => [
                    'bom_quantity',
                    'consumption_based_on_which_materials_order_including_yy',
                    'gmts_order_qty',
                    'costing_yy_in_sms',
                ],
            ],
            'buyer_liability_value' => [
                'formula' => 'buyer_liability * pi_rate',
                'source_header_keys' => [
                    'buyer_liability',
                    'pi_rate',
                ],
            ],
            'final_status' => [
                'source_header_keys' => [
                    'material_order_status',
                    'arrival_status',
                    'payment_status',
                ],
            ],
        ];
    }

    private function normalizeHeaderPositions(): void
    {
        DB::transaction(function () {
            $this->resequencePositions();
        });
    }

    private function resequencePositions(): void
    {
        $headers = ExcelHeader::query()
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'position']);

        $position = 1;

        foreach ($headers as $header) {
            if ((int) $header->position !== $position) {
                ExcelHeader::whereKey($header->id)->update(['position' => $position]);
            }

            $position++;
        }
    }
}
