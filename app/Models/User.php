<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'avatar',
        'phonenumber',
        'email',
        'password',
        'is_admin',
    ];

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return Str::startsWith($this->avatar, ['http://', 'https://'])
                ? $this->avatar
                : url('storage/'.ltrim($this->avatar, '/'));
        }

        return url('/images/default-avatar.png');
    }

    protected static function booted(): void
    {
        static::deleted(function (User $user) {
            self::deleteStoredAvatar($user->getRawOriginal('avatar'));
            $user->tokens()->delete();
        });
    }

    /**
     * Canonical form of a phone number: Persian and Arabic digits become Latin,
     * and Iranian numbers become 09xxxxxxxxx.
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $phone
        );
        $digits = (string) preg_replace('/\D/', '', $phone);

        return str_starts_with($digits, '989') ? '0'.substr($digits, 2) : $digits;
    }

    /**
     * Whether a normalized phone number is an Iranian mobile number.
     */
    public static function isValidMobile(string $phone): bool
    {
        return preg_match('/^09\d{9}$/', $phone) === 1;
    }

    /**
     * Sign out every device, except the one using the given token if any.
     */
    public function revokeTokensExcept(mixed $currentToken = null): void
    {
        $this->tokens()
            ->when($currentToken instanceof PersonalAccessToken, fn ($tokens) => $tokens->whereKeyNot($currentToken->getKey()))
            ->delete();
    }

    /**
     * Point the user at a newly stored avatar, then remove the previous file.
     * The old file goes last, so a failed update never costs the user their picture.
     */
    public function replaceAvatar(string $path): void
    {
        $previousAvatar = $this->getRawOriginal('avatar');

        $this->update(['avatar' => $path]);

        self::deleteStoredAvatar($previousAvatar);
    }

    /**
     * The public-disk path of an avatar value, or null when it is not one of our
     * uploaded avatars (empty, an external URL, or anything outside avatars/).
     */
    public static function storedAvatarPath(?string $avatar): ?string
    {
        if (blank($avatar) || Str::startsWith($avatar, ['http://', 'https://'])) {
            return null;
        }

        $path = ltrim($avatar, '/');

        return Str::startsWith($path, 'avatars/') && ! Str::contains($path, '..') ? $path : null;
    }

    private static function deleteStoredAvatar(?string $avatar): void
    {
        $path = self::storedAvatarPath($avatar);

        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function consoles(): HasMany
    {
        return $this->hasMany(Console::class);

    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
