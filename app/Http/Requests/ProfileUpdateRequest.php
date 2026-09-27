<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Normalize the Employee ID before the unique check, so "hapl-0231" is
     * refused when "HAPL-0231" exists — on PostgreSQL as well as MySQL.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('employee_id')) {
            $this->merge(['employee_id' => Employee::normalizeId($this->input('employee_id'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'phone' => User::phoneRules(),
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];

        // Employee ID can be self-set only while the account has none. Once
        // linked it is a login identifier and only an admin may change it, so
        // the field drops out of the rules and a posted value is ignored.
        //
        // Unique against EVERY employee record, linked or not — stricter than
        // the admin form on purpose: an unlinked record is a real person on
        // the HR books, and claiming it here would let someone sign in as the
        // ID an admin later links to that person.
        if (! $this->user()->employee()->exists()) {
            $rules['employee_id'] = [
                ...Employee::idFormatRules(),
                Rule::unique('employees', 'employee_id'),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return User::phoneMessages() + Employee::idFormatMessages() + [
            'employee_id.unique' => 'This Employee ID is already in use. Please contact the administrator.',
        ];
    }
}
