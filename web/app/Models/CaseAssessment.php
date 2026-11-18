<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A researcher's explicit judgement for one case and one code, concept or
 * proposition: present, absent or unclear. Without one, a cell with no
 * segments means "not examined", never "absent".
 */
class CaseAssessment extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const ITEMS = ['code', Concept::TYPE, Proposition::TYPE];

    public const STATES = ['present', 'absent', 'unclear'];

    protected $fillable = ['project_id', 'case_id', 'item_type', 'item_id', 'state', 'note', 'updated_by'];
}
