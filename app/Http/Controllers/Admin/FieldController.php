<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\OrderSection;
use App\Models\OrderField;
use App\Models\Role;

class FieldController extends Controller
{
    // List all sections
    public function index()
    {
        $sections = OrderSection::with('role')->get();
        return view('admin.fields.index', compact('sections'));
    }

    // Create section form
    public function create()
    {
        $roles = Role::all();
        return view('admin.fields.create', compact('roles'));
    }

    // Store section
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'role_id' => 'required|exists:roles,id',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'required|boolean',
        ]);

        OrderSection::create($request->all());
        return redirect()->route('admin.fields.index')->with('success', 'Section created successfully.');
    }

    // Edit section
    public function edit(OrderSection $section)
    {
        $roles = Role::all();
        return view('admin.fields.edit', compact('section', 'roles'));
    }

    // Update section
    public function update(Request $request, OrderSection $section)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'role_id' => 'required|exists:roles,id',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'required|boolean',
        ]);

        $section->update($request->all());
        return redirect()->route('admin.fields.index')->with('success', 'Section updated successfully.');
    }

    // Delete section
    public function destroy(OrderSection $section)
    {
        $section->delete();
        return redirect()->route('admin.fields.index')->with('success', 'Section deleted successfully.');
    }

    // Create field form
    public function createField(OrderSection $section)
    {
        return view('admin.fields.create_field', compact('section'));
    }

    // Store field
    public function storeField(Request $request, OrderSection $section)
    {
        $request->validate([
            'field_label' => 'required|string|max:255',
            'field_key' => 'required|string|max:255|unique:order_fields,field_key',
            'field_type' => 'required|in:text,number,select,date,checkbox',
            'options' => 'nullable|string',
            'is_required' => 'boolean',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $data = $request->all();
        if ($data['field_type'] === 'select') {
            $data['options'] = explode(',', $data['options']);
        } else {
            $data['options'] = null;
        }

        $section->fields()->create($data);
        return redirect()->route('admin.fields.index')->with('success', 'Field added successfully.');
    }

    // Edit field
    public function editField(OrderSection $section, OrderField $field)
    {
        return view('admin.fields.edit_field', compact('section', 'field'));
    }

    // Update field
    public function updateField(Request $request, OrderSection $section, OrderField $field)
    {
        $request->validate([
            'field_label' => 'required|string|max:255',
            'field_key' => 'required|string|max:255|unique:order_fields,field_key,' . $field->id,
            'field_type' => 'required|in:text,number,select,date,checkbox',
            'options' => 'nullable|string',
            'is_required' => 'boolean',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $data = $request->all();
        if ($data['field_type'] === 'select') {
            $data['options'] = explode(',', $data['options']);
        } else {
            $data['options'] = null;
        }

        $field->update($data);
        return redirect()->route('admin.fields.index')->with('success', 'Field updated successfully.');
    }

    // Delete field
    public function destroyField(OrderSection $section, OrderField $field)
    {
        $field->delete();
        return redirect()->route('admin.fields.index')->with('success', 'Field deleted successfully.');
    }

    public function reorderFields(Request $request, OrderSection $section)
{
    $orderIds = $request->order; // array of field IDs in new order

    foreach ($orderIds as $sortOrder => $id) {
        OrderField::where('id', $id)->update(['sort_order' => $sortOrder]);
    }

    return response()->json(['status' => 'success']);
    }
}
