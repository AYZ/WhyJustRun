<?php
class EventsController extends AppController {

	var $name = 'Events';
	
	var $components = array(
	   'RequestHandler',
	   'Media' => array(
	       'type' => 'Event',
	       'allowedExts' => array('xml'),
	       'thumbnailSizes' => array('')
	   )
    );
	var $helpers = array("Time", "Geocode", "Form", "TimePlus", 'Leaflet', 'Markdown', 'Session', 'Media');
	
	function beforeFilter()
	{
        parent::beforeFilter();
		$this->Auth->allow('index', 'upcoming', 'past', 'major', 'view', 'rendering', 'planner', 'embed');
	}
	
	// TODO-RWP Move ajax request $starTimestamp, $endTimestamp to separate call
	function index($startTimestampOrDate = null, $endTimestamp = null) 
	{
		$this->set('title_for_layout', 'Calendar');
		// If is a date
		if(strpos($startTimestampOrDate, "-")) {
			$dateParts = explode("-", $startTimestampOrDate);
			$this->set('year', $dateParts[2]);
			$this->set('month', $dateParts[1]);
			$this->set('day', $dateParts[0]);
		} else {
            $this->set("events", array());
			if(!empty($startTimestamp) && !empty($endTimestamp)) {
				$this->set("events", $this->Event->findAllBetween($startTimestamp,$endTimestamp));
			} elseif(!empty($_GET["start"]) && !empty($_GET["end"])) {
				$this->set("events", $this->Event->findAllBetween($_GET["start"],$_GET["end"]));
			} else {
				// do nothing
			}
			
			$this->set('year', date('Y'));
			$this->set('month', date('m'));
			$this->set('day', date('d'));
		}
      $this->loadModel("Series");
      $this->set('series', $this->Series->findAllByIsCurrent(1));
	}
    // Displays a rendering of the results (manually uploaded)
    function rendering($id) {
        $this->Media->display($id);
    }
	
	function edit($id = null) {
        if($id == null) {
            // Add event
            $this->checkAuthorization(Configure::read('Privilege.Event.edit'));
    		$this->set('title_for_layout', 'Add Event');
            $this->_setLists();
            $this->set('type', 'Add');
        } else {
            // Edit event
    		$this->set('title_for_layout', 'Edit Event');
            $this->set('type', 'Edit');
            $this->_checkEditAuthorization($id);
            $this->_setLists();
            $this->Event->id = $id;
        }

	
		if ($this->request->is('post')) {
			// Organizer data from JSON
			$this->_parseJson();
			
			// Don't save the default location
			if(floatval($this->request->data['Event']['lat']) == Configure::read('Club.lat') && floatval($this->request->data['Event']['lng']) == Configure::read('Club.lng')) {
				$this->request->data['Event']['lat'] = null;
				$this->request->data['Event']['lng'] = null;
			}

			// Delete old organizers, as they will be re-created
			$this->Event->Organizer->deleteAll(array('Organizer.event_id' => $this->request->data["Event"]["id"]));
			
			if ($this->Event->saveAll($this->request->data)) {
				$this->Session->setFlash('The event has been updated.', 'flash_success');
				$this->redirect('/events/view/'.$this->Event->id);
			}
            else {
				$this->Session->setFlash('The event could not be updated.');
            }
		}

		$this->_editData();

	}
	
	function planner() {
	   $this->set('title_for_layout', 'Event Planner');
	   $this->set('maps', $this->Event->Map->findRarelyUsed());
	   $this->set('users', $this->Event->Organizer->findVolunteers());
	}

    function printableEntries($id) {
		$contain = array(
			'Series', 
			'Course' => array(
				'Result' => array('User.name', 'User.id', 'User.username', 'User.si_number', 'User.is_member')
			)
		);
		$event = $this->Event->find('first', array('conditions' => array('Event.id' => $id), 'contain' => $contain));
        $event["Result"] = @Set::sort($event["Result"], "{n}.User.name", 'asc');
        $this->set('event', $event);
        $this->layout = 'printable';

    }
    function uploadMaps($id=null) {
        $this->_checkEditAuthorization($id);
        $this->set('title_for_layout', 'Upload Course Maps');
        $this->set('courses', $this->Event->Course->findAllByEventId($id));
        $this->set('id', $id);
    }
	
	function _setLists() {
        $this->set('maps', $this->Event->Map->find('list', array('order'=>'Map.name')));
		$this->set('series', $this->Event->Series->find('list'));
		$this->set('groups', $this->Event->Group->find('list'));
	}

	function view($id = null) {
		$contain = array(
			'Series', 
			'Organizer' => array(
				'User' => array(
					'fields' => array('id', 'name', 'username')
				), 'Role'
			), 
			'Course' => array(
				'Result' => array('User.name', 'User.id', 'User.username', 'User.si_number')
			)
		);
		$event = $this->Event->find('first', array('conditions' => array('Event.id' => $id), 'contain' => $contain));
		$user = AuthComponent::user();
		
		if(!$event) {
            $this->Session->setFlash("The event requested couldn't be found.");
            $this->redirect('/');
		}
		
		$startTime = new DateTime($event["Event"]["utc_date"]);
		if($this->_isBeforeNow($startTime)) {
			$event["Event"]["completed"] = true;
		} else {
			$event["Event"]["completed"] = false;
		}
		
		// Sort results by time, non-competitive if the results are posted
		if($event["Event"]["results_posted"] === "1") {
			foreach($event["Course"] as &$course) {
				// Suppressing warnings after getting issues with http://rporter.whyjustrun.ca/Events/view/650
				$course["Result"] = @Set::sort($course["Result"], "{n}.time", 'asc');
				$course["Result"] = @Set::sort($course["Result"], "{n}.non_competitive", 'asc');
			}
		}

		foreach($event["Course"] as &$course) {
			$registered = false;

			if($this->Auth->loggedIn()) {
				foreach($course["Result"] as $result) {
					if($user["id"] === $result["User"]["id"]) {
						$registered = true;
						break;
					}
				}
			}
			
			$course["registered"] = $registered;
		}
		
		$this->set('title_for_layout', $event["Event"]["name"]);
		$this->set('event', $event);
		$this->set('edit', $this->Event->Organizer->isAuthorized($id, AuthComponent::user('id')));
	}
    function results($id) {
        return $this->view($id);
    }

	function editResults($id) {
		$this->set('title_for_layout', 'Edit Results');
		$this->_checkEditAuthorization($id);
		$this->Event->id = $id;
		if ($this->request->is('post')) {
			$courses = json_decode($this->request->data["Event"]["courses"]);
			$updatedResults = array();
			foreach($courses as $course) {
				foreach($course->results as $result) {
					$processedResult = array();
					$processedResult["user_id"] = $result->user->id;
					$processedResult["course_id"] = $course->id;
					$processedResult["time"] = $this->_timeFromParts($result->hours, $result->minutes, $result->seconds);
					
					if(empty($result->non_competitive)) {
						$processedResult["non_competitive"] = false;
					} else {
						$processedResult["non_competitive"] = $result->non_competitive;
					}
					
					if(empty($result->needs_ride)) {
						$processedResult["needs_ride"] = false;
					} else {
						$processedResult["needs_ride"] = $result->needs_ride;
					}
					
					if(empty($result->offering_ride)) {
						$processedResult["offering_ride"] = false;
					} else {
						$processedResult["offering_ride"] = $result->offering_ride;
					}

					if(!empty($result->id)) {
						$processedResult["id"] = $result->id;
					}
					array_push($updatedResults, $processedResult);					
				}
                if(empty($updatedResults) || $this->Event->Course->Result->saveAll($updatedResults)) {
                    $this->Event->Course->Result->calculatePoints($course->id);
                    if($this->Event->saveField('results_posted', $this->request->data["Event"]["results_posted"])) {
                    }
                }
			}
            $this->redirect("/events/view/${id}");
		}
		$this->set('eventId', $id);
	}

	function upcoming($limit)
	{
        $time = new DateTime();
		return $this->Event->find('all', array('limit' => $limit, 'contain' => array('Series.id'), 'conditions' => array('Event.date >=' => $time->format("Y-m-d H:i:s")), 'order' => 'Event.date ASC'));
	}
	function major($limit)
	{
        $time = new DateTime();
        return $this->Event->find('all', array('limit' => $limit, 'contain' => array('Series.id'), 'conditions' => array('Event.date >=' => $time->format("Y-m-d H:i:s"), 'Event.series_id =' => 1), 'order' => 'Event.date ASC'));
	}
	
	function past($limit)
	{
        $time = new DateTime();
		return $this->Event->find('all', array('limit' => $limit, 'contain' => array('Series.id'), 'conditions' => array('Event.date <=' => $time->format("Y-m-d H:i:s")), 'order' => 'Event.date DESC'));
	}
	
	function _isBeforeNow($dateTime) {
		$now = new DateTime();
		if(($dateTime->getTimestamp() - $now->getTimestamp()) > 0) {
			return false;
		} else {
			return true;
		}
	}


	function _checkEditAuthorization($id) {
		// Check authorization
		if(!$this->Event->Organizer->isAuthorized($id, AuthComponent::user('id'))) {
			$this->Session->setFlash('You are not authorized to edit this event.');
			$this->redirect('/events/view/'.$id);
		}
	}

	function _parseJson() {
		$organizers = json_decode($this->request->data["Event"]["organizers"]);
		unset($this->request->data["Event"]["organizers"]);
		if(count($organizers) > 0) {
			$this->request->data["Organizer"] = array();
			foreach($organizers as $organizer) {
				$convertedOrganizer = array( 'role_id' => $organizer->role->id, 'user_id' => $organizer->id);
				// Event id won't be known for a new event, it will be automagically added by CakePHP upon save
				if(!empty($this->request->data["Event"]['id'])) {
					$convertedOrganizer['event_id'] = $this->request->data["Event"]['id'];
				}
				array_push($this->request->data["Organizer"], $convertedOrganizer);
			}
		}
		
		$courses = json_decode($this->request->data["Event"]["courses"]);
		unset($this->request->data["Event"]["courses"]);
		if(count($courses) > 0) {
			$this->request->data["Course"] = array();
			foreach($courses as $course) {
				array_push($this->request->data["Course"], array('id' => $course->id, 'name' => $course->name, 'description' => $course->description, 'distance' => $course->distance, 'climb' => $course->climb));
			}
		}
	}

	function _editData() {
		if(empty($this->Event->id)) {
			$this->request->data["Event"]["organizers"] = "[]";
			$this->request->data["Event"]["courses"] = "[]";
			return;
		}
		$conditions = array('Event.id' => $this->Event->id);
		$contain = array('Organizer.Role', 'Course', 'Organizer.User.name', 'Map', 'Series');
		$this->data = $this->Event->find('first', array('contain' => $contain, 'conditions' => $conditions));
		$courses = array();
		foreach($this->data["Course"] as $originalCourse) {
			$course = array();
			$course["id"] = $originalCourse["id"];
			$course["name"] = $originalCourse["name"];
			$course["distance"] = $originalCourse["distance"];
			$course["climb"] = $originalCourse["climb"];
			$course["description"] = $originalCourse["description"];

			array_push($courses, $course);
		}
		
		$organizers = array();
		// Organizer data to JSON
		foreach($this->data["Organizer"] as $originalOrganizer) {
			$organizer = array();
			$organizer["id"] = $originalOrganizer["user_id"];
			$organizer["name"] = $originalOrganizer["User"]["name"];
			$organizer["role"]["name"] = $originalOrganizer["Role"]["name"];
			$organizer["role"]["id"] = $originalOrganizer["Role"]["id"];

			array_push($organizers, $organizer);
		}

		$this->request->data["Event"]["organizers"] = json_encode($organizers);
		$this->request->data["Event"]["courses"] = json_encode($courses);
	}  

	function _timeFromParts($hours, $minutes, $seconds) {
		if(intval($hours) === 0 && intval($minutes) === 0 && intval($seconds) === 0) {
			return null;
		}
	
		$hours = str_pad($hours, 2, '0', STR_PAD_LEFT);
		$minutes = str_pad($minutes, 2, '0', STR_PAD_LEFT);
		$seconds = str_pad($seconds, 2, '0', STR_PAD_LEFT);
		
		return $hours . ":" . $minutes . ":" . $seconds;
	}
}
?>
