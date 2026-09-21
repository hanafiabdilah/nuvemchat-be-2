<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // ⚠️ No second factor by default, and the previous default was not
            // merely noisy — it was ten random characters in `two_factor_secret`
            // plus a `two_factor_confirmed_at`, i.e. every factory user looked
            // enrolled while holding a secret nothing can decrypt. Harmless
            // while the API ignored the column; the moment sign-in started
            // honouring it, every one of those accounts became unreachable.
            // A user who has one says so, with withTwoFactor().
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model does not have two-factor authentication configured.
     *
     * Now the same as the default. Kept because two tests say it out loud, and
     * a test that states what it depends on is worth more than one that relies
     * on a default staying put.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    /**
     * A user with a second factor enrolled, in Fortify's storage format.
     *
     * Fortify owns how these columns are written — encrypted, with the recovery
     * codes in the clear inside the blob — so the fixture writes them the same
     * way rather than inventing a second format that only tests understand.
     */
    public function withTwoFactor(string $secret = 'ABCDEFGHIJKLMNOP'): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaaa-bbbbb', 'ccccc-ddddd'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
