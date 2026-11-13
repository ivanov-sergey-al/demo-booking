<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $validated = $request->validated();        

        $booking = DB::transaction(function () use ($request, $validated): Booking {
            $startsAt = Carbon::parse($validated['starts_at'])->utc();
            $endsAt = Carbon::parse($validated['ends_at'])->utc();

            Room::query()->lockForUpdate()->findOrFail($validated['room_id']);

            $hasConflict = Booking::query()
                ->where('room_id', $validated['room_id'])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages(['room_id' => 'Комната уже занята в указанный промежуток времени.']);
            }

            return Booking::create([
                'room_id' => $validated['room_id'],
                'user_id' => auth()->id(),
                'title' => $validated['title'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        });

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(int $id)
    {
        $booking = Booking::where('id', $id)->firstOrFail();

        if ($booking->user_id !== auth()->id()) {
            abort(403);
        }

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
