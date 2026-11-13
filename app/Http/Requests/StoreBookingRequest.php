<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\Carbon;
use Closure;
use Throwable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Illuminate\Contracts\Validation\Validator as ContractsValidator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreBookingRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, Closure|string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['bail', 'required', 'integer', 'exists:rooms,id'],
            'title' => ['bail', 'required', 'string', 'max:255'],
            'starts_at' => ['bail', 'required', 'string', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
            'ends_at' => ['bail', 'required', 'string', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
        ];
    }

    protected function failedValidation(ContractsValidator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * @return Closure
     */
    public function after(): Closure
    {
        return function (Validator $validator): void {
            if ($validator->errors()->hasAny(['room_id', 'starts_at', 'ends_at'])) {
                return;
            }

            $room = Room::query()->with('office')->find($this->integer('room_id'));
            if ($room === null) {
                return;
            }

            $startsAt = Carbon::parse($this->string('starts_at'))->setTimezone($room->office->timezone);
            $endsAt = Carbon::parse($this->string('ends_at'))->setTimezone($room->office->timezone);
            $durationInSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

            if ($startsAt->second !== 0 || $startsAt->microsecond !== 0 || $startsAt->minute % 15 !== 0) {
                $validator->errors()->add('starts_at', 'Время начала должно быть кратно 15 минутам.');
            }

            if ($endsAt->second !== 0 || $endsAt->microsecond !== 0 || $endsAt->minute % 15 !== 0) {
                $validator->errors()->add('ends_at', 'Время окончания должно быть кратно 15 минутам.');
            }

            if ($durationInSeconds < 900 || $durationInSeconds > 14400 || $durationInSeconds % 900 !== 0) {
                $validator->errors()->add('ends_at', 'Длительность бронирования должна составлять от 15 минут до 4 часов с шагом 15 минут.');
            }

            $workdayStartsAt = $startsAt->clone()->startOfDay()->setTime(9, 0);
            $workdayEndsAt = $endsAt->clone()->startOfDay()->setTime(20, 0);

            if (!$startsAt->isSameDay($endsAt) || $startsAt->lessThan($workdayStartsAt) || $endsAt->greaterThan($workdayEndsAt)) {
                $validator->errors()->add('starts_at', 'Бронирование должно целиком укладываться в рабочее время офиса: с 09:00 до 20:00.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.integer' => 'Идентификатор комнаты должен быть целым числом.',
            'room_id.exists' => 'Указанная комната не найдена.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи не должно превышать 255 символов.',
            'starts_at.required' => 'Укажите время начала.',
            'starts_at.date_format' => 'Время начала должно быть датой в формате ISO 8601 со смещением часового пояса.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.date_format' => 'Время окончания должно быть датой в формате ISO 8601 со смещением часового пояса.',
        ];
    }
}
