<?php

namespace Gsebastiao\LaravelAuthz\Models;

use Gsebastiao\LaravelAuthz\Concerns\ResolvedAuditableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Toda lógica de CRUD (addUserToGroup, updateGroupMembership,
 * removeUserFromGroup) vive em Models\Authorization.php — este model é
 * só a definição Eloquent (fillable, casts, relações).
 */
class GroupUser extends Model
{
    use SoftDeletes;
    use ResolvedAuditableTrait;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The storage format of the model's date columns.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    public const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    public const UPDATED_AT = 'updated_at';

    /**
     * The name of the "deleted at" column.
     *
     * @var string|null
     */
    public const DELETED_AT = 'deleted_at';

    /**
     * Controls mass assignment protection.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'group_id',
        'status',
        'start_date',
        'end_date',
        'is_primary',
        'observacao',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'group_id' => 'integer',
            'status' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_primary' => 'boolean',
        ];
    }

    public function __construct(array $attributes = [])
    {
        $this->setTable(config('authz.tables.groups_users', 'auth_groups_users'));

        parent::__construct($attributes);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        $userModel = config('authz.user_model') ?? config('auth.providers.users.model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'user_id');
    }
}
