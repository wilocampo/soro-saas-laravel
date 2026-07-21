<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Compact notification payloads for the topbar menu and the
     * Notifications page — one shape, one place.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function notificationSummaries(int $limit = 50): Collection
    {
        return $this->notifications()->limit($limit)->get()->map(fn ($n) => [
            'id' => $n->id,
            'type' => $n->data['type'] ?? 'info',
            'title' => $n->data['title'] ?? class_basename($n->type),
            'message' => $n->data['message'] ?? '',
            'time' => $n->created_at->diffForHumans(),
            'read' => $n->read_at !== null,
        ]);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
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
            'password' => 'hashed',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Get the tenant that the user belongs to.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
