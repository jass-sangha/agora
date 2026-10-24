<?php

namespace Chat\Database\Factories;

use Chat\Enums\ConversationType;
use Chat\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'type' => ConversationType::Private,
            'name' => null,
            'last_activity_at' => now(),
        ];
    }

    public function group(): static
    {
        return $this->state(fn () => [
            'type' => ConversationType::Group,
            'name' => $this->faker->words(2, true),
        ]);
    }
}
