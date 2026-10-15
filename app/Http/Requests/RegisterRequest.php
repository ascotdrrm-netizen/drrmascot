<?php

namespace App\Http\Requests;

use App\Rules\SchoolOnCampus;
use App\Rules\ValidName;
use App\Rules\ValidPassword;
use App\Support\PersonName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Public self-registration. The name is entered as first name, optional
 * middle initial and last name, and combined into personnel.full_name by
 * fullName() — the database keeps its single column.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100', new ValidName()],
            // One letter, optionally followed by a period ("t", "T" or "T.").
            'middle_initial' => ['nullable', 'string', 'regex:/^\s*\p{L}\s*\.?\s*$/u'],
            'last_name' => ['required', 'string', 'max:100', new ValidName()],
            'campus_id' => ['required', 'exists:campuses,campus_id'],
            // The columns' sizes (personnel.email varchar(100), contact_number varchar(20)):
            // anything longer used to reach the database and crash the page.
            'email' => ['required', 'email', 'max:100', 'unique:personnel,email'],
            // Required (thesis panel, Engineering): SMS alerts reach people by this number.
            'contact_number' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'confirmed', new ValidPassword()],
            // Required at self-registration (the placeholder posts "" and is
            // rejected here, not only by the browser), and it must be a school
            // on the chosen campus. Attribution only: the role stays hardcoded
            // to Coordinator whatever the school. personnel.school_id itself
            // stays nullable — the Admin's personnel edit form still allows no
            // school (e.g. General Services Unit staff).
            'school_id' => ['required', new SchoolOnCampus(ctype_digit((string) $this->input('campus_id')) ? (int) $this->input('campus_id') : 0)],
        ];
    }

    public function messages(): array
    {
        return [
            'middle_initial.regex' => 'The middle initial must be a single letter (for example "T" or "T.").',
            'school_id.required' => 'Please select your school.',
            'contact_number.required' => 'Please enter your contact number: emergency SMS alerts are sent to it.',
        ];
    }

    /** The combined name must still fit personnel.full_name. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['first_name', 'middle_initial', 'last_name'])) {
                    return;
                }

                if (mb_strlen($this->fullName()) > PersonName::MAX_LENGTH) {
                    $validator->errors()->add('last_name', sprintf(
                        'Your full name is too long: first name, middle initial and last name together must be at most %d characters.',
                        PersonName::MAX_LENGTH
                    ));
                }
            },
        ];
    }

    /** "First M. Last", or "First Last" without a middle initial. */
    public function fullName(): string
    {
        return PersonName::combine($this->input('first_name'), $this->input('middle_initial'), $this->input('last_name'));
    }
}
