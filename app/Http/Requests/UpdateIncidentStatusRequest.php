<?php

namespace App\Http\Requests;

use App\Support\HelixdeskIncidentAuthorization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIncidentStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($user->type === 'end_user') {
            return true;
        }

        $incident = $this->route('incident');

        if ($incident === null || $incident->queue_id === null) {
            return false;
        }

        return HelixdeskIncidentAuthorization::hasIncidentPermission($user, 'update', (int) $incident->queue_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', 'in:New,Assigned,In Progress,Waiting for User,Resolved,Closed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_status.in' => 'Invalid incident status transition target.',
        ];
    }
}
