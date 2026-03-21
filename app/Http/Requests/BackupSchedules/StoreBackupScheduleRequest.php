<?php

namespace App\Http\Requests\BackupSchedules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBackupScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'backup_destination_id' => [
                'required',
                'integer',
                Rule::exists('backup_destinations', 'id')->where('user_id', $userId),
            ],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'time' => ['required', 'date_format:H:i'],
            'day_of_week' => ['nullable', 'required_if:frequency,weekly', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['nullable', 'required_if:frequency,monthly', 'integer', 'min:1', 'max:28'],
            'retention_count' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'backup_destination_id.required' => 'Please select a backup destination.',
            'backup_destination_id.exists' => 'The selected backup destination is invalid.',
            'frequency.required' => 'Please select a backup frequency.',
            'time.required' => 'Please specify a backup time.',
            'time.date_format' => 'Time must be in HH:MM format.',
            'day_of_week.required_if' => 'Please select a day of the week for weekly backups.',
            'day_of_month.required_if' => 'Please select a day of the month for monthly backups.',
        ];
    }
}
