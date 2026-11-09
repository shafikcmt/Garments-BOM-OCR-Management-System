<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An order progress meeting: minutes and the action items that came out of it. */
class Meeting extends Model
{
    protected $fillable = ['buyer_id', 'mother_style_id', 'title', 'meeting_date', 'attendees', 'minutes', 'next_meeting_date', 'created_by', 'updated_by'];

    protected $casts = ['meeting_date' => 'date', 'next_meeting_date' => 'date'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function motherStyle()
    {
        return $this->belongsTo(MotherStyle::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actions()
    {
        return $this->hasMany(FollowUpTask::class)->orderBy('due_date')->orderBy('id');
    }
}
