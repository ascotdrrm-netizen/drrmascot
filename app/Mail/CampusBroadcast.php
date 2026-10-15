<?php

namespace App\Mail;

use App\Models\Broadcast;
use Illuminate\Mail\Mailable;

class CampusBroadcast extends Mailable
{
    public function __construct(public Broadcast $broadcast) {}

    public function build(): static
    {
        return $this->subject('ASCOT DRRMS: '.$this->broadcast->title)->view('emails.campus-broadcast');
    }
}
