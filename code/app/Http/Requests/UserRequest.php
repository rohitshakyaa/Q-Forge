<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gating handled by route middleware
    }

    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->route('user');
        $emailRule = Rule::unique('users', 'email');
        if ($user) {
            $emailRule->ignore($user->id);
        }

        // Password is required when provisioning a new account (admin sets an
        // initial password), and optional on update (blank = leave unchanged).
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'email' => [$required, 'email', 'max:255', $emailRule],
            'role' => [$required, Rule::in(['admin', 'teacher'])],
            'password' => [$this->isMethod('POST') ? 'required' : 'nullable', 'string', 'min:8'],
        ];
    }
}
