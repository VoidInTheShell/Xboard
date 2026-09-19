<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\Updates\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function exchange(Request $request)
    {
        $version = '/^v(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(-dev\\.[1-9][0-9]*\\.[1-9][0-9]*)?$/D';
        $data = $request->validate([
            'enrollment_token' => 'required|string|min:32|max:128',
            'architecture' => ['required', Rule::in(['linux/amd64', 'linux/arm64'])],
            'installation_method' => ['required', Rule::in(['systemd', 'docker', 'compose'])],
            'node_version' => ['required', 'string', 'regex:' . $version],
            'updater_version' => ['required', 'string', 'regex:' . $version],
            'node_instance_id' => ['required', 'string', 'max:80', 'regex:/\\A[a-zA-Z0-9_.-]+\\z/'],
        ]);

        return $this->success($this->enrollments->exchange($data));
    }
}
