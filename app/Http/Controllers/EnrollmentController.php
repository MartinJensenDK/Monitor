<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Agent\Scripts;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\AuditLog;
use App\Domain\Devices;
use App\Domain\EnrollmentKeys;
use App\Domain\Groups;
use App\Domain\Locations;

/**
 * Making and revoking the keys that let a machine enrol, and showing the two
 * commands that install the agent.
 */
final class EnrollmentController extends Controller
{
    private const FRESH_KEY = 'enrollment.fresh';

    public function index(Request $request): Response
    {
        $this->requireTable();

        // A key is readable exactly once, on the page that made it. After this
        // render it is gone from the session and only its hash remains.
        $fresh = Session::get(self::FRESH_KEY);
        Session::forget(self::FRESH_KEY);

        return $this->view($request, 'pages/enrollment', [
            'title' => t('device.enrollment_title'),
            'keys' => EnrollmentKeys::all(),
            'fresh' => is_string($fresh) ? $fresh : null,
            'groups' => Groups::all(),
            'locations' => Locations::isReady() ? Locations::all() : [],
            'baseUrl' => Scripts::baseUrl(),
            'checksums' => [
                'linux' => Scripts::checksum('linux/install.sh'),
                'windows' => Scripts::checksum('windows/install.ps1'),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireTable();

        $name = trim((string) $request->input('name', ''));
        $days = $request->int('expires_days', 30);
        $maxUses = $request->int('max_uses', 0);

        $validator = Validator::make($request->all())
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 120)
            ->custom('expires_days', $days >= 0 && $days <= 365, 'An enrolment key can last from a day to a year, or never expire.')
            ->custom('max_uses', $maxUses >= 0 && $maxUses <= 10000, 'Cap the number of machines between 1 and 10000, or leave it blank for no cap.');

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect('/devices/enrollment');
        }

        $result = EnrollmentKeys::create([
            'name' => $name,
            'kind' => (string) $request->input('kind', 'auto'),
            'group_id' => $request->int('group_id', 0),
            'location_id' => $request->int('location_id', 0),
            'max_uses' => $maxUses,
            'expires_at' => $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null,
        ]);

        Session::put(self::FRESH_KEY, $result['key']);

        // The key itself is deliberately absent from the audit line: the log is
        // read by more people than the page that created it.
        AuditLog::record('enrollment_key.created', 'enrollment_key', $result['id'], 'Created enrolment key "' . $name . '"');

        $this->success('Key created. Copy it now — it is not shown again.');

        return $this->redirect('/devices/enrollment');
    }

    public function revoke(Request $request): Response
    {
        $key = $this->findOrFail($request->intParam('id'));

        EnrollmentKeys::revoke((int) $key['id']);
        AuditLog::record('enrollment_key.revoked', 'enrollment_key', (int) $key['id'], 'Revoked enrolment key "' . $key['name'] . '"');

        $this->success('Key revoked. Machines already enrolled with it keep reporting.');

        return $this->redirect('/devices/enrollment');
    }

    public function destroy(Request $request): Response
    {
        $key = $this->findOrFail($request->intParam('id'));

        EnrollmentKeys::delete((int) $key['id']);
        AuditLog::record('enrollment_key.deleted', 'enrollment_key', (int) $key['id'], 'Deleted enrolment key "' . $key['name'] . '"');

        $this->success('Key deleted.');

        return $this->redirect('/devices/enrollment');
    }

    /** @return array<string,mixed> */
    private function findOrFail(int $id): array
    {
        $this->requireTable();

        $key = EnrollmentKeys::find($id);
        if ($key === null) {
            throw HttpException::notFound('That enrolment key does not exist.');
        }

        return $key;
    }

    private function requireTable(): void
    {
        if (!Devices::isReady()) {
            throw HttpException::notFound(
                'Agent enrolment needs a database update that has not run yet. Run php bin/migrate.php on the server.'
            );
        }
    }
}
