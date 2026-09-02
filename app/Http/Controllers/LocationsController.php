<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\AuditLog;
use App\Domain\Locations;

final class LocationsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requireTable();

        return $this->view($request, 'pages/locations', [
            'title' => 'Locations',
            'locations' => Locations::all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->requireTable();

        return $this->view($request, 'pages/location-form', [
            'title' => 'New location',
            'location' => null,
            'monitors' => [],
        ]);
    }

    public function edit(Request $request): Response
    {
        $location = $this->findOrFail($request->intParam('id'));

        return $this->view($request, 'pages/location-form', [
            'title' => (string) $location['name'],
            'location' => $location,
            'monitors' => Locations::monitors((int) $location['id']),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireTable();

        $data = $this->validated($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = Locations::create($data);
        AuditLog::record('location.created', 'location', $id, 'Added location ' . $data['name']);
        $this->success('Location added. Pick it on a monitor to put it on the map.');

        return $this->redirect('/locations/' . $id);
    }

    public function update(Request $request): Response
    {
        $location = $this->findOrFail($request->intParam('id'));

        $data = $this->validated($request, (int) $location['id']);
        if ($data instanceof Response) {
            return $data;
        }

        Locations::update((int) $location['id'], $data);
        AuditLog::record('location.updated', 'location', (int) $location['id'], 'Updated location ' . $data['name']);
        $this->success('Location saved.');

        return $this->redirect('/locations/' . $location['id']);
    }

    public function destroy(Request $request): Response
    {
        $location = $this->findOrFail($request->intParam('id'));

        Locations::delete((int) $location['id']);
        AuditLog::record('location.deleted', 'location', (int) $location['id'], 'Deleted location ' . $location['name']);
        $this->success('Location deleted. The monitors that were there are untouched, just no longer on the map.');

        return $this->redirect('/locations');
    }

    /** @return array<string,mixed>|Response */
    private function validated(Request $request, int $locationId = 0): array|Response
    {
        $name = trim((string) $request->input('name', ''));
        $latitude = (string) $request->input('latitude', '');
        $longitude = (string) $request->input('longitude', '');

        $validator = Validator::make($request->all())
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 120)
            ->maxLength('address', 'Address', 255)
            ->custom('name', !Locations::nameTaken($name, $locationId), 'There is already a location called that.')
            ->custom('latitude', is_numeric($latitude), 'Give the latitude as a number, such as 55.6761.')
            ->custom('longitude', is_numeric($longitude), 'Give the longitude as a number, such as 12.5683.');

        if (is_numeric($latitude)) {
            $validator->custom('latitude', abs((float) $latitude) <= 90, 'Latitude runs from -90 at the south pole to 90 at the north.');
        }
        if (is_numeric($longitude)) {
            $validator->custom('longitude', abs((float) $longitude) <= 180, 'Longitude runs from -180 to 180.');
        }

        if ($validator->fails()) {
            Session::flashInput($request->all());
            $this->error((string) $validator->firstError());

            return $this->redirect($locationId > 0 ? '/locations/' . $locationId : '/locations/new');
        }

        return [
            'name' => $name,
            'address' => trim((string) $request->input('address', '')),
            'latitude' => round((float) $latitude, 6),
            'longitude' => round((float) $longitude, 6),
        ];
    }

    /** @return array<string,mixed> */
    private function findOrFail(int $id): array
    {
        $this->requireTable();

        $location = Locations::find($id);
        if ($location === null) {
            throw HttpException::notFound('That location does not exist.');
        }

        return $location;
    }

    /**
     * Locations arrived in migration 006. Until it has run there is no table
     * to read, so say that rather than letting a query fail.
     */
    private function requireTable(): void
    {
        if (!Locations::isReady()) {
            throw HttpException::notFound(
                'Locations need a database update that has not run yet. Run php bin/migrate.php on the server.'
            );
        }
    }
}
