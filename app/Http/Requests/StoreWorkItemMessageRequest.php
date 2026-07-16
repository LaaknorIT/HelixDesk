<?php

namespace App\Http\Requests;

use App\Support\HelixdeskWorkItemAuthorization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkItemMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $workItem = $this->route('work_item');

        if ($user === null || $workItem === null || $workItem->tenant_id === null) {
            return false;
        }

        if (! $user->canOperateInTenant((int) $workItem->tenant_id)) {
            return false;
        }

        if ($user->type === 'end_user') {
            return true;
        }

        if ($workItem->queue_id === null) {
            return false;
        }

        return HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'update', (int) $workItem->queue_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'parent_message_id' => ['nullable', 'integer'],
        ];
    }
}
