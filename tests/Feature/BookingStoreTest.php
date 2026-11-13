<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_booking_in_office_timezone_and_assigns_authenticated_user(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();
        $room = Room::factory()->for(Office::factory()->novosibirsk())->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-06-01T05:00:00+03:00',
            'ends_at' => '2026-06-01T06:00:00+03:00',
            'user_id' => $anotherUser->id,
        ]));

        $response->assertCreated()
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'starts_at' => '2026-06-01 02:00:00',
            'ends_at' => '2026-06-01 03:00:00',
        ]);
    }

    public function test_allows_back_to_back_bookings_in_same_room(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(Office::factory())->create();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-06-01 07:00:00',
            'ends_at' => '2026-06-01 08:00:00',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-06-01T11:00:00+03:00',
            'ends_at' => '2026-06-01T12:00:00+03:00',
        ]));

        $response->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    #[DataProvider('validBookingDurations')]
    public function test_creates_booking_at_allowed_duration_boundaries(string $startsAt, string $endsAt): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(Office::factory())->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]));

        $response->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validBookingDurations(): array
    {
        return [
            'fifteen minutes' => ['2026-06-01T10:00:00+03:00', '2026-06-01T10:15:00+03:00'],
            'four hours' => ['2026-06-01T10:00:00+03:00', '2026-06-01T14:00:00+03:00'],
        ];
    }

    public function test_returns_422_when_booking_overlaps_an_existing_booking(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(Office::factory())->create();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-06-01 07:00:00',
            'ends_at' => '2026-06-01 08:00:00',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-06-01T09:45:00+03:00',
            'ends_at' => '2026-06-01T10:15:00+03:00',
        ]));

        $response->assertUnprocessable()
            ->assertInvalid(['room_id' => 'Комната уже занята в указанный промежуток времени.']);

        $this->assertDatabaseCount('bookings', 1);
    }

    #[DataProvider('invalidBookingTimes')]
    public function test_returns_422_for_invalid_booking_time(string $startsAt, string $endsAt, string $invalidField, string $message): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(Office::factory())->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]));

        $response->assertUnprocessable()
            ->assertInvalid([$invalidField => $message]);

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidBookingTimes(): array
    {
        return [
            'duration less than fifteen minutes' => ['2026-06-01T10:00:00+03:00', '2026-06-01T10:10:00+03:00', 'ends_at', 'Длительность бронирования должна составлять от 15 минут до 4 часов с шагом 15 минут.'],
            'duration more than four hours' => ['2026-06-01T10:00:00+03:00', '2026-06-01T14:15:00+03:00', 'ends_at', 'Длительность бронирования должна составлять от 15 минут до 4 часов с шагом 15 минут.'],
            'start is not on a fifteen minute boundary' => ['2026-06-01T10:05:00+03:00', '2026-06-01T10:35:00+03:00', 'starts_at', 'Время начала должно быть кратно 15 минутам.'],
            'starts before office hours' => ['2026-06-01T08:45:00+03:00', '2026-06-01T09:15:00+03:00', 'starts_at', 'Бронирование должно целиком укладываться в рабочее время офиса: с 09:00 до 20:00.'],
            'ends after office hours' => ['2026-06-01T19:45:00+03:00', '2026-06-01T20:15:00+03:00', 'starts_at', 'Бронирование должно целиком укладываться в рабочее время офиса: с 09:00 до 20:00.'],
        ];
    }

    public function test_returns_422_for_missing_booking_data(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', []);

        $response->assertUnprocessable()
            ->assertInvalid([
                'room_id' => 'Укажите комнату.',
                'title' => 'Укажите название встречи.',
                'starts_at' => 'Укажите время начала.',
                'ends_at' => 'Укажите время окончания.',
            ]);
    }

    public function test_returns_422_when_datetime_has_no_timezone_offset(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(Office::factory())->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-06-01T10:00:00',
        ]));

        $response->assertUnprocessable()
            ->assertInvalid(['starts_at' => 'Время начала должно быть датой в формате ISO 8601 со смещением часового пояса.']);
    }

    private function payload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'title' => 'Планирование',
            'starts_at' => '2026-06-01T10:00:00+03:00',
            'ends_at' => '2026-06-01T11:00:00+03:00',
        ], $overrides);
    }
}
