<?php

namespace App\Services;

use App\Models\ExcelHeader;

/**
 * Single source of truth for BOM-sheet header aliases and key normalisation.
 *
 * The alias map + normalisation used to live privately inside
 * ExcelFileController. It is extracted here so the workspace recalc, the
 * ledger→cell sync, and any other consumer resolve headers the same way —
 * no duplicated alias maps.
 */
class HeaderAliasResolver
{
    /**
     * Normalise a header name/key for matching: lowercase, collapse punctuation
     * to single underscores. Returns null for null input.
     */
    public function normalize($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim((string) $value));
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = str_replace(["'", '’'], '', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = preg_replace('/_+/', '_', $value);

        return trim($value, '_');
    }

    /**
     * Canonical header key => list of human aliases seen in uploaded sheets.
     *
     * @return array<string, array<int, string>>
     */
    public function aliases(): array
    {
        return [
            'po_no' => ['material po number', 'material po no', 'material purchase order', 'material purchase order number'],
            'po_date' => ['po date', 'material po date', 'material purchase order date'],
            'style_name' => ['style', 'buyer name'],
            'contract_number' => ['initial contract number', 'contract number', 'gmnts po number', 'gmts po number', 'po number'],
            'contract_shipment_date' => ['contract shipment date', 'initial contract shipment date', 'po shipment date'],
            'bom_quantity' => ['bom quantity', 'bom qty', 'bom qnty'],
            'customer_contract_quantity' => ['customer contract quantity', 'customer contract qty', 'customer po quantity', 'order qty', 'gmts order qty', 'gmts order quantity'],
            'booking_consumption_from_cad' => ['booking consumption from cad', 'booking consumption', 'cad consumption', 'booking cons from cad'],
            'initial_consumption' => ['booking consumption from cad', 'initial consumption', 'booking yy', 'consumption'],
            'costing_yy_in_sms' => ['costing yy in sms', 'costing yy', 'yy in sms', 'costing yy sms'],
            'wastage_for_ordering_percent' => ['% wastage for ordering', 'waste %', 'wastage %'],
            'consumption_incl_yy' => ['consumption based on which materials order including yy', 'consumption including yy', 'consumption incl yy', 'yy + waste %'],
            'short_excess_ordered' => ['(short)/excess ordered', '(short) / excess ordered', 'short excess ordered'],
            'payment_reqd_date' => ["payment req'd date", 'payment reqd date', 'payment required date'],
            'pi_summary_submission_date' => ['pi summary submission date', 'pi summary submission'],
            'pmt_doc_no' => ['pmt doc no', 'payment doc no', 'payment reference number', 'payment ref no'],
            'committed_ex_mill' => ['committed ex mill', 'committed x-fty date', 'committed x fty date', 'committed ex-fty date', 'committed ex fty date'],
            'bl_awb_no' => ['bl / awb no', 'bl awb no', 'bl no', 'awb no'],
            'committed_etd' => ['committed etd', 'commited etd'],
            'committed_eta' => ['committed eta', 'committed e.t.a', 'committed arrival date'],
            'committed_inhouse' => ['committed inhouse', 'committed in house', 'committed in-house'],
            'actual_inhouse' => ['actual inhouse', 'actual in-house'],
            'pcd_as_per_committed_inhouse' => ['pcd as per committed inhouse', 'rm inh as per committed inhouse'],
            'invoiced_qty_scm' => ['invoiced qty(scm)', 'invoiced qty scm', 'invoiced qty'],
            'invoiced_rate_scm' => ['invoiced rate(scm)', 'invoiced rate scm', 'invoiced rate'],
            'invoiced_amount_scm' => ['invoiced amount(scm)', 'invoiced amount scm', 'invoiced amount'],
            'invoiced_qty_store' => ['invoiced qty(store)', 'invoiced qty store'],
            'invoiced_rate_store' => ['invoiced rate(store)', 'invoiced rate store'],
            'invoiced_amount_store' => ['invoiced amount(store)', 'invoiced amount store'],
            'receipt_qty' => ['receipt qty', 'in-house / receipt qty', 'in house receipt qty'],
            'gmnts_po_number' => ['gmnts po number', 'gmts po number', 'gmt po number'],
            'gmts_order_qty' => ['gmts order qty', 'gmts order quantity', 'gmt order qty', 'customer contract quantity', 'customer contract qty'],
            'production_wastage_percent' => ['production wastage %', 'prod. wastage %', 'prod wastage %'],
            'production_cons_incl_wastage' => ['production consumption including wastage', 'production cons including wastage', 'prod. yy + wastage', 'prod yy waste'],
            'excess_shortage' => ['excess / (shortage)', 'excess shortage', '(short) / excess in-house qty'],
            'buyer_liability' => ['buyer liability'],
            'buyer_liability_value' => ['buyer liability value'],
            'liability_based_on_receiving' => ['liability based on receiving'],
            'short_excess_issued' => ['(short)/ excess issued', '(short) / excess issued', 'short excess issued'],
            'material_cost_value' => ['material cost value'],
            'dead_stock_value' => ['dead stock value'],
            'short_excess_value' => ['short & excess value', 'short and excess value'],
        ];
    }

    /**
     * Normalised match keys for a canonical header key: the canonical itself
     * plus every alias, normalised and de-duplicated.
     *
     * @return array<int, string>
     */
    public function matchKeysFor(string $canonical): array
    {
        return collect($this->aliases()[$canonical] ?? [])
            ->map(fn ($alias) => $this->normalize($alias))
            ->push($this->normalize($canonical))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Active ExcelHeader ids matching a canonical key (by name/key/formula),
     * optionally restricted to one owner role.
     *
     * @return array<int, int>
     */
    public function headerIdsForCanonical(string $canonical, ?int $ownerRoleId = null): array
    {
        $matchKeys = collect($this->matchKeysFor($canonical));

        if ($matchKeys->isEmpty()) {
            return [];
        }

        return ExcelHeader::query()
            ->where('is_active', true)
            ->when($ownerRoleId !== null, fn ($q) => $q->where('owner_role_id', $ownerRoleId))
            ->get(['id', 'header_name', 'header_key', 'formula_key', 'owner_role_id'])
            ->filter(function (ExcelHeader $header) use ($matchKeys) {
                $keys = collect([
                    $this->normalize($header->header_name),
                    $this->normalize($header->header_key),
                    $this->normalize($header->formula_key),
                ])->filter();

                return $keys->intersect($matchKeys)->isNotEmpty();
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The single ExcelHeader id for a canonical key (first match), or null.
     */
    public function resolveHeaderId(string $canonical, ?int $ownerRoleId = null): ?int
    {
        return $this->headerIdsForCanonical($canonical, $ownerRoleId)[0] ?? null;
    }
}
