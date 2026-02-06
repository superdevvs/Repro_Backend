<?php

namespace App\Services\ReproAi;

class IntentRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            [
                'name' => 'book_shoot',
                'min_confidence' => 1.3,
                'min_keyword_matches' => 1,
                'required_groups' => [
                    ['book ', 'book a', 'book new', 'book another', 'schedule', 'create booking', 'set up'],
                    ['shoot', 'session', 'booking'],
                ],
                'disqualifying_keywords' => [
                    'reschedule',
                    'cancel',
                    'manage booking',
                    'change booking',
                    'availability',
                    'check availability',
                ],
                'keywords' => [
                    'book a new shoot' => 1.4,
                    'book a shoot' => 1.2,
                    'book new shoot' => 1.3,
                    'book another shoot' => 1.2,
                    'book shoot' => 1.1,
                    'schedule a shoot' => 1.2,
                    'schedule shoot' => 1.1,
                    'schedule a session' => 1.0,
                    'book a session' => 1.0,
                    'create a booking' => 1.0,
                    'set up a shoot' => 1.1,
                    'new shoot' => 0.8,
                    'book' => 0.4,
                    'shoot' => 0.4,
                ],
                'negative_keywords' => [
                    'reschedule',
                    'cancel',
                    'manage',
                    'availability',
                    'check availability',
                ],
                'examples' => [
                    'Book a new shoot',
                    'Can you book a shoot for next week?',
                    'Schedule a shoot for 24 Ocean Ave',
                    'Create a booking for Friday',
                ],
            ],
            [
                'name' => 'manage_booking',
                'min_confidence' => 1.1,
                'min_keyword_matches' => 1,
                'required_groups' => [
                    ['reschedule', 'cancel', 'change', 'update', 'manage', 'move'],
                    ['booking', 'shoot', 'appointment', 'service', 'services'],
                ],
                'disqualifying_keywords' => [
                    'new shoot',
                    'book a new shoot',
                    'book a shoot',
                ],
                'keywords' => [
                    'manage booking' => 1.2,
                    'manage a booking' => 1.2,
                    'manage existing booking' => 1.4,
                    'change services' => 1.2,
                    'change service' => 1.1,
                    'update services' => 1.2,
                    'update service' => 1.1,
                    'reschedule' => 1.1,
                    'reschedule shoot' => 1.3,
                    'reschedule booking' => 1.3,
                    'cancel booking' => 1.3,
                    'cancel shoot' => 1.2,
                    'change booking' => 1.0,
                    'change shoot' => 0.9,
                    'change date' => 0.8,
                    'move shoot' => 0.9,
                    'update booking' => 1.0,
                    'manage' => 0.4,
                    'booking' => 0.4,
                ],
                'negative_keywords' => [
                    'new shoot',
                    'book a new shoot',
                    'book a shoot',
                    'schedule a shoot',
                ],
                'examples' => [
                    'Manage an existing booking',
                    'Reschedule my shoot',
                    'Cancel a booking',
                    'Change my shoot date',
                ],
            ],
            [
                'name' => 'availability',
                'min_confidence' => 1.0,
                'min_keyword_matches' => 1,
                'required_groups' => [
                    ['availability', 'available', 'slots', 'openings', 'next available', 'check availability'],
                ],
                'keywords' => [
                    'check availability' => 1.2,
                    'photographer availability' => 1.1,
                    'availability' => 0.9,
                    'any slots' => 0.9,
                    'next available' => 0.8,
                    'time slots' => 0.8,
                    'openings' => 0.7,
                    'slots' => 0.7,
                    'available' => 0.5,
                ],
                'negative_keywords' => [
                    'reschedule',
                    'cancel',
                ],
                'examples' => [
                    'Check photographer availability',
                    'Any slots next Thursday?',
                    'Is anyone available tomorrow?',
                ],
            ],
        ];
    }
}
