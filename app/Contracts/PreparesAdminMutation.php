<?php

namespace App\Contracts;

use Illuminate\Http\Request;

interface PreparesAdminMutation
{
    /** Prepare slow external I/O before RequestLog opens its write transaction. */
    public function prepareMutation(Request $request): void;
}
