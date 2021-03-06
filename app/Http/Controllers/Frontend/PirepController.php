<?php

namespace App\Http\Controllers\Frontend;

use App\Exceptions\UserNotAtAirport;
use App\Facades\Utils;
use App\Http\Requests\CreatePirepRequest;
use App\Http\Requests\UpdatePirepRequest;
use App\Interfaces\Controller;
use App\Models\Enums\PirepSource;
use App\Models\Enums\PirepState;
use App\Models\Pirep;
use App\Repositories\AircraftRepository;
use App\Repositories\AirlineRepository;
use App\Repositories\AirportRepository;
use App\Repositories\Criteria\WhereCriteria;
use App\Repositories\PirepFieldRepository;
use App\Repositories\PirepRepository;
use App\Services\FareService;
use App\Services\GeoService;
use App\Services\PirepService;
use App\Services\UserService;
use App\Support\Units\Time;
use Carbon\Carbon;
use Flash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Log;

/**
 * Class PirepController
 * @package App\Http\Controllers\Frontend
 */
class PirepController extends Controller
{
    private $aircraftRepo,
            $airlineRepo,
            $fareSvc,
            $geoSvc,
            $pirepRepo,
            $airportRepo,
            $pirepFieldRepo,
            $pirepSvc,
            $userSvc;

    /**
     * PirepController constructor.
     * @param AircraftRepository   $aircraftRepo
     * @param AirlineRepository    $airlineRepo
     * @param AirportRepository    $airportRepo
     * @param FareService          $fareSvc
     * @param GeoService           $geoSvc
     * @param PirepRepository      $pirepRepo
     * @param PirepFieldRepository $pirepFieldRepo
     * @param PirepService         $pirepSvc
     * @param UserService          $userSvc
     */
    public function __construct(
        AircraftRepository $aircraftRepo,
        AirlineRepository $airlineRepo,
        AirportRepository $airportRepo,
        FareService $fareSvc,
        GeoService $geoSvc,
        PirepRepository $pirepRepo,
        PirepFieldRepository $pirepFieldRepo,
        PirepService $pirepSvc,
        UserService $userSvc
    ) {
        $this->aircraftRepo = $aircraftRepo;
        $this->airlineRepo = $airlineRepo;
        $this->pirepRepo = $pirepRepo;
        $this->airportRepo = $airportRepo;
        $this->pirepFieldRepo = $pirepFieldRepo;

        $this->fareSvc = $fareSvc;
        $this->geoSvc = $geoSvc;
        $this->pirepSvc = $pirepSvc;
        $this->userSvc = $userSvc;
    }

    /**
     * Dropdown with aircraft grouped by subfleet
     * @param null $user
     * @return array
     */
    public function aircraftList($user = null, $add_blank = false)
    {
        $aircraft = [];
        $subfleets = $this->userSvc->getAllowableSubfleets($user);

        if ($add_blank) {
            $aircraft[''] = '';
        }

        foreach ($subfleets as $subfleet) {
            $tmp = [];
            foreach ($subfleet->aircraft as $ac) {
                $tmp[$ac->id] = $ac['name'].' - '.$ac['registration'];
            }

            $aircraft[$subfleet->name] = $tmp;
        }

        return $aircraft;
    }

    /**
     * Save any custom fields found
     * @param Pirep   $pirep
     * @param Request $request
     */
    protected function saveCustomFields(Pirep $pirep, Request $request)
    {
        $custom_fields = [];
        $pirep_fields = $this->pirepFieldRepo->all();
        foreach ($pirep_fields as $field) {
            if (!$request->filled($field->slug)) {
                continue;
            }

            $custom_fields[] = [
                'name'   => $field->name,
                'value'  => $request->input($field->slug),
                'source' => PirepSource::MANUAL
            ];
        }

        Log::info('PIREP Custom Fields', $custom_fields);
        $this->pirepSvc->updateCustomFields($pirep->id, $custom_fields);
    }

    /**
     * Save the fares that have been specified/saved
     * @param Pirep   $pirep
     * @param Request $request
     * @throws \Exception
     */
    protected function saveFares(Pirep $pirep, Request $request)
    {
        $fares = [];
        foreach ($pirep->aircraft->subfleet->fares as $fare) {
            $field_name = 'fare_'.$fare->id;
            if (!$request->filled($field_name)) {
                $count = 0;
            } else {
                $count = $request->input($field_name);
            }

            $fares[] = [
                'fare_id' => $fare->id,
                'count'   => $count,
            ];
        }

        $this->fareSvc->saveForPirep($pirep, $fares);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Prettus\Repository\Exceptions\RepositoryException
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $where = [['user_id', $user->id]];
        $where[] = ['state', '<>', PirepState::CANCELLED];

        $this->pirepRepo->pushCriteria(new WhereCriteria($request, $where));
        $pireps = $this->pirepRepo->orderBy('created_at', 'desc')->paginate();

        return view('pireps.index', [
            'user'   => $user,
            'pireps' => $pireps,
        ]);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $pirep = $this->pirepRepo->find($id);
        if (empty($pirep)) {
            Flash::error('Pirep not found');

            return redirect(route('frontend.pirep.index'));
        }

        $map_features = $this->geoSvc->pirepGeoJson($pirep);

        return view('pireps.show', [
            'pirep'        => $pirep,
            'map_features' => $map_features,
        ]);
    }

    /**
     * Return the fares form for a given aircraft
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function fares(Request $request)
    {
        $aircraft_id = $request->input('aircraft_id');
        $aircraft = $this->aircraftRepo->find($aircraft_id);

        return view('pireps.fares', [
            'aircraft'  => $aircraft,
            'read_only' => false,
        ]);
    }

    /**
     * Create a new flight report
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function create()
    {
        $user = Auth::user();

        return view('pireps.create', [
            'aircraft'      => null,
            'read_only'     => false,
            'airline_list'  => $this->airlineRepo->selectBoxList(true),
            'aircraft_list' => $this->aircraftList($user, true),
            'airport_list'  => $this->airportRepo->selectBoxList(true),
            'pirep_fields'  => $this->pirepFieldRepo->all(),
            'field_values'  => [],
        ]);
    }

    /**
     * @param CreatePirepRequest $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Exception
     */
    public function store(CreatePirepRequest $request)
    {
        // Create the main PIREP
        $pirep = new Pirep($request->post());
        $pirep->user_id = Auth::user()->id;

        # Are they allowed at this airport?
        if (setting('pilots.only_flights_from_current')
            && Auth::user()->curr_airport_id !== $pirep->dpt_airport_id) {
            return $this->flashError(
                'You are currently not at the departure airport!',
                'frontend.pireps.create'
            );
        }

        # Can they fly this aircraft?
        if (setting('pireps.restrict_aircraft_to_rank', false)
            && !$this->userSvc->aircraftAllowed(Auth::user(), $pirep->aircraft_id)) {
            return $this->flashError(
                'You are not allowed to fly this aircraft!',
                'frontend.pireps.create'
            );
        }

        # is the aircraft in the right place?
        if (setting('pireps.only_aircraft_at_dpt_airport')
            && $pirep->aircraft_id !== $pirep->dpt_airport_id) {
            return $this->flashError(
                'This aircraft is not positioned at the departure airport!',
                'frontend.pireps.create'
            );
        }

        # Make sure this isn't a duplicate
        $dupe_pirep = $this->pirepSvc->findDuplicate($pirep);
        if ($dupe_pirep !== false) {
            return $this->flashError(
                'This PIREP has already been filed.',
                'frontend.pireps.create'
            );
        }

        // Any special fields
        $hours = (int) $request->input('hours', 0);
        $minutes = (int) $request->input('minutes', 0);
        $pirep->flight_time = Utils::hoursToMinutes($hours) + $minutes;

        // Put the time that this is currently submitted
        $attrs['submitted_at'] = Carbon::now('UTC');

        $pirep = $this->pirepSvc->create($pirep);
        $this->saveCustomFields($pirep, $request);
        $this->saveFares($pirep, $request);
        $this->pirepSvc->saveRoute($pirep);

        return redirect(route('frontend.pireps.show', ['id' => $pirep->id]));
    }

    /**
     * Show the form for editing the specified Pirep.
     * @param  int $id
     * @return mixed
     */
    public function edit($id)
    {
        $pirep = $this->pirepRepo->findWithoutFail($id);
        if (empty($pirep)) {
            Flash::error('Pirep not found');

            return redirect(route('frontend.pireps.index'));
        }

        $time = new Time($pirep->flight_time);
        $pirep->hours = $time->hours;
        $pirep->minutes = $time->minutes;

        # set the custom fields
        foreach ($pirep->fields as $field) {
            $pirep->{$field->slug} = $field->value;
        }

        # set the fares
        foreach ($pirep->fares as $fare) {
            $field_name = 'fare_'.$fare->fare_id;
            $pirep->{$field_name} = $fare->count;
        }

        return view('pireps.edit', [
            'pirep'         => $pirep,
            'aircraft'      => $pirep->aircraft,
            'aircraft_list' => $this->aircraftList(),
            'airline_list'  => $this->airlineRepo->selectBoxList(),
            'airport_list'  => $this->airportRepo->selectBoxList(),
            'pirep_fields'  => $this->pirepFieldRepo->all(),
        ]);
    }

    /**
     * @param                    $id
     * @param UpdatePirepRequest $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Prettus\Validator\Exceptions\ValidatorException
     * @throws \Exception
     */
    public function update($id, UpdatePirepRequest $request)
    {
        $pirep = $this->pirepRepo->findWithoutFail($id);

        if (empty($pirep)) {
            Flash::error('Pirep not found');

            return redirect(route('admin.pireps.index'));
        }

        $orig_route = $pirep->route;

        $attrs = $request->all();

        # Fix the time
        $attrs['flight_time'] = Time::init(
            $attrs['minutes'],
            $attrs['hours'])->getMinutes();

        $pirep = $this->pirepRepo->update($attrs, $id);

        // A route change in the PIREP, so update the saved points in the ACARS table
        if ($pirep->route !== $orig_route) {
            $this->pirepSvc->saveRoute($pirep);
        }

        $this->saveCustomFields($pirep, $request);
        $this->saveFares($pirep, $request);

        Flash::success('Pirep updated successfully.');

        return redirect(route('frontend.pireps.show', ['id' => $pirep->id]));
    }
}
