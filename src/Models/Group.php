<?php

namespace Gsebastiao\LaravelAuthz\Models;

use Gsebastiao\LaravelAuthz\Concerns\ResolvedAuditableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Toda lógica de CRUD (createGroup, updateGroup, deleteGroup) vive em
 * Models\Authorization.php — este model é só a definição Eloquent
 * (fillable, casts). Ver Authorization.php para as operações de
 * escrita.
 */
class Group extends Model
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
        'name',
        'description',
        'status',
        'tenant_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'tenant_id' => 'integer',
            'tenant_key' => 'integer',
        ];
    }

    public function __construct(array $attributes = [])
    {
        $this->setTable(config('authz.tables.groups', 'auth_groups'));

        parent::__construct($attributes);
    }
}
