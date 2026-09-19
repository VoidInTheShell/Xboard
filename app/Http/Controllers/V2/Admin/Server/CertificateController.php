<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ServerCertificate;
use App\Services\Certificates\CertificateService;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function __construct(private readonly CertificateService $certificates) {}

    public function fetch(Request $request)
    {
        $machineId = $this->machineId($request);
        return $this->success(
            $this->certificates->listForMachine($machineId)
                ->map(fn (ServerCertificate $certificate) => $this->certificates->toControlPlaneArray($certificate))
                ->values()
                ->all()
        );
    }

    public function validateConfig(Request $request)
    {
        $params = $this->validatedDraft($request);
        $existing = !empty($params['id'])
            ? $this->certificates->findForMachine((string) $params['id'], (int) $params['machine_id'])
            : null;
        $this->certificates->validateDraft($params, $existing);
        return $this->success(['valid' => true]);
    }

    public function save(Request $request)
    {
        $params = $this->validatedDraft($request);
        $certificate = $this->certificates->save($params);
        return $this->success($this->certificates->toControlPlaneArray($certificate));
    }

    public function renew(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|string|max:64',
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'confirmation' => 'required|string|in:CONFIRM server.certificate.renew',
        ]);
        $certificate = $this->certificates->renew((string) $params['id'], (int) $params['machine_id']);
        return $this->success($this->certificates->toControlPlaneArray($certificate));
    }

    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|string|max:64',
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'confirmation' => 'required|string|in:CONFIRM server.certificate.drop',
        ]);
        $this->certificates->drop((string) $params['id'], (int) $params['machine_id']);
        return $this->success(true);
    }

    private function validatedDraft(Request $request): array
    {
        return $request->validate([
            'id' => 'nullable|string|max:64',
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'name' => 'required|string|max:255',
            'source_type' => 'required|string|in:' . implode(',', ServerCertificate::SOURCES),
            'domains' => 'required|array|min:1|max:50',
            'domains.*' => 'required|string|max:253',
            'auto_renew' => 'nullable|boolean',
            'email' => 'nullable|email|max:255',
            'dns_provider' => 'nullable|string|max:128',
            'dns_credentials' => 'nullable|string|max:20000',
            'certificate_path' => 'nullable|string|max:2048',
            'private_key_path' => 'nullable|string|max:2048',
            'certificate_content' => 'nullable|string|max:500000',
            'private_key_content' => 'nullable|string|max:500000',
        ]);
    }

    private function machineId(Request $request): int
    {
        $value = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
        ]);
        $machineId = (int) $value['machine_id'];
        if ($machineId < 1) throw new ApiException('服务器不存在。', 404);
        return $machineId;
    }
}
