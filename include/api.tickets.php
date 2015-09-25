<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';
include_once INCLUDE_DIR.'class.user.php';

class TicketApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "alert", "autorespond", "source", "topicId",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "message", "ip", "priorityId"
        );
        # Fetch dynamic form field names for the given help topic and add
        # the names to the supported request structure
        if (isset($data['topicId'])
                && ($topic = Topic::lookup($data['topicId']))
                && ($form = $topic->getForm())) {
            foreach ($form->getDynamicFields() as $field)
                $supported[] = $field->get('name');
        }

        # Ticket form fields
        # TODO: Support userId for existing user
        if(($form = TicketForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        # User form fields
        if(($form = UserForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        if(!strcasecmp($format, 'email')) {
            $supported = array_merge($supported, array('header', 'mid',
                'emailId', 'to-email-id', 'ticketId', 'reply-to', 'reply-to-name',
                'in-reply-to', 'references', 'thread-type',
                'flags' => array('bounce', 'auto-reply', 'spam', 'viral'),
                'recipients' => array('*' => array('name', 'email', 'source'))
                ));

            $supported['attachments']['*'][] = 'cid';
        }

        return $supported;
    }

    /*
     Validate data - overwrites parent's validator for additional validations.
    */
    function validate(&$data, $format, $strict=true) {
        global $ost;

        //Call parent to Validate the structure
        if(!parent::validate($data, $format, $strict) && $strict)
            $this->exerr(400, __('Unexpected or invalid data received'));

        // Use the settings on the thread entry on the ticket details
        // form to validate the attachments in the email
        $tform = TicketForm::objects()->one()->getForm();
        $messageField = $tform->getField('message');
        $fileField = $messageField->getWidget()->getAttachments();

        // Nuke attachments IF API files are not allowed.
        if (!$messageField->isAttachmentsEnabled())
            $data['attachments'] = array();

        //Validate attachments: Do error checking... soft fail - set the error and pass on the request.
        if ($data['attachments'] && is_array($data['attachments'])) {
            foreach($data['attachments'] as &$file) {
                if ($file['encoding'] && !strcasecmp($file['encoding'], 'base64')) {
                    if(!($file['data'] = base64_decode($file['data'], true)))
                        $file['error'] = sprintf(__('%s: Poorly encoded base64 data'),
                            Format::htmlchars($file['name']));
                }
                // Validate and save immediately
                try {
                    $file['id'] = $fileField->uploadAttachment($file);
                }
                catch (FileUploadError $ex) {
                    $file['error'] = $file['name'] . ': ' . $ex->getMessage();
                }
            }
            unset($file);
        }

        return true;
    }

    /**
    * Create the ticket.
    */
    function create($format) {
        $this->_format = $format;

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $ticket = $this->createTicket($this->getRequest($format));
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));


        if (!strcasecmp($format, 'json')) {
            $result = array(
                'user' => $ticket->getUserId(),
                'number' => $ticket->getNumber(),
                );
            $this->response(201, json_encode($result));
        } else {
            // TODO change the ouput for email and xml
            $this->response(201, $ticket->getNumber());
        }
    }

    /* private helper functions */

    function createTicket($data) {

        # Pull off some meta-data
        $alert       = (bool) (isset($data['alert'])       ? $data['alert']       : true);
        $autorespond = (bool) (isset($data['autorespond']) ? $data['autorespond'] : true);

        # Assign default value to source if not defined, or defined as NULL
        $data['source'] = isset($data['source']) ? $data['source'] : 'API';

        # Create the ticket with the data (attempt to anyway)
        $errors = array();

        $ticket = Ticket::create($data, $errors, $data['source'], $autorespond, $alert);
        # Return errors (?)
        if (count($errors)) {
            if(isset($errors['errno']) && $errors['errno'] == 403)
                return $this->exerr(403, __('Ticket denied'));
            else
                return $this->exerr(
                        400,
                        __("Unable to create new ticket: validation errors").":\n"
                        .Format::array_implode(": ", "\n", $errors)
                        );
        } elseif (!$ticket) {
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));
        }

        return $ticket;
    }

    function processEmail($data=false) {

        if (!$data)
            $data = $this->getEmailRequest();

        if (($thread = ThreadEntry::lookupByEmailHeaders($data))
                && ($t=$thread->getTicket())
                && ($data['staffId']
                    || !$t->isClosed()
                    || $t->isReopenable())
                && $thread->postEmail($data)) {
            return $thread->getTicket();
        }
        return $this->createTicket($data);
    }

    function search($format) {
      $this->initalizeUser($format);
      $params = $this->getRequest($format);

      $search = $ost->searcher;
      $search = new SearchInterface();
      $results = $search->find($params['query'], isset($params['criteria']) ? $params['criteria'] : array());
      $this->response(
        201,
        json_encode(
          array(
            'results' => $results,
            'count' => count($results)
          )
        )
      );
    }

    /**
    * Get ticket by number, not id
    */
    function getTicket($format) {
        $this->initalizeUser($format);
        $params = $this->getRequest($format);

        $ticket = Ticket::lookupByNumber($params['number']);
        if (empty($ticket) || $ticket->number != $params['number'] || $ticket->getEmail() != $params['email']) {
            return $this->exerr(404, __("Unable to find the ticket"));
        } else {
            $ticket_data = $ticket->ht;
            // display more fields
            $ticket_data['subject'] = $ticket->getSubject();
            $ticket_data['dept_name'] = $ticket->getDeptName();
            if($team = $ticket->getTeam()) {
                $team_name = $team->getName();
            } else {
                $team_name = '';
            }
            $ticket_data['team_name'] = $team_name;
            // TODO get close note and status

            $this->response(201, json_encode($ticket_data));
        }
    }

    function getThreadEntry($format) {
      $this->initalizeUser($format);
      $params = $this->getRequest($format);

      $entry = new ThreadEntry($params['id']);

      if($entry->id == $params['id']) {
        $data = $entry->ht;
        $data['attachments'] = $entry->getAttachmentUrls();
        $this->response(201, json_encode($data));
      } else
        $this->response(201, json_encode(array('id' => $params['id'], 'error' => 'ThreadEntryNotFound')));

    }

    /**
    * change ticket statues by number, email, status_id, and comments.
    */
    function changeTicketStatus($format) {
        $this->initalizeUser($format);
        $params = $this->getRequest($format);

        $ticket = Ticket::lookupByNumber($params['number']);
        if (empty($ticket) || $ticket->number != $params['number'] || $ticket->getEmail() != $params['email']) {
            return $this->exerr(404, __("Unable to find the ticket"));
        }

        if(empty($params['comments']))
            return $this->exerr(500, __("Unable to change ticket status: comments missing"));

        // id is from ost_ticket_status table, should add an api to list them
        if($ticket->setStatus($params['status_id'], $params['comments'])) {
            $this->response(
                201, 
                json_encode(array(
                    'ticket_id' => $ticket->id,
                    'status_id' => $ticket->getStatusId()
                ))
            );
        } else {
            $this->response(500, __("Failed to change ticket status"));
        }
    }

    /**
    * Get client thread for a given ticket number
    */
    function getClientThread($format) {
        $this->initalizeUser($format);
        $params = $this->getRequest($format);

        $ticket = Ticket::lookupByNumber($params['number']);
        if (empty($ticket) || $ticket->number != $params['number'] || $ticket->getEmail() != $params['email']) {
            return $this->exerr(404, __("Unable to find the ticket"));
        }
        $client_thread_entries = $ticket->getClientThread();

        // add attachments
        foreach ($client_thread_entries as &$entry) {
            $entry_model = $ticket->getThreadEntry($entry['id']);
            $entry['attachments'] = $entry_model->getAttachmentUrls();
        }
        $this->response(201, json_encode($client_thread_entries));
    }

    /**
    * Get all tickets under the given user email
    */
    function getTickets($format) {
        $this->initalizeUser($format);
        $params = $this->getRequest($format);

        $user_model = User::lookupByEmail($params['email']);
        if (empty($user_model)) {
            return $this->exerr(404, __("Unable to find the user"));
        }
        // reference class.search.php:SearchInterface::find
        // $search = new SearchInterface();
        // $results = $search->find('', $criteria);
        // $this->response(
        //     201,
        //     json_encode(
        //       array(
        //         'data' => $results,
        //         'count' => count($results)
        //       )
        //     )
        // );
        // refrence class.orm.php:InstrumentedList
        // $user_model->tickets, get class.user.php:TicketModel
        $ticket_models = $user_model->tickets->asArray();
        $tickets = array();
        foreach ($ticket_models as $ticket_model) {
            $ticket = $ticket_model->ht;
            // display more fields
            $real_ticket = Ticket::lookup($ticket_model->getId());
            $ticket['subject'] = $real_ticket->getSubject();
            $ticket['dept_name'] = $real_ticket->getDeptName();
            if($team=$real_ticket->getTeam()) {
                $team_name = $team->getName();
            } else {
                $team_name = '';
            }
            $ticket['team_name'] = $team_name;
            $tickets[] = $ticket;
        }
        $this->response(
            201,
            json_encode($tickets)
        );
    }

    /**
    * add message form a client on ticket of given number
    */
    function postMessage($format) {
        $this->initalizeUser($format);
        $params = $this->getRequest($format);

        if (!isset($params['email']) ||
            !isset($params['number']) ||
            !isset($params['message'])) {
            return $this->exerr(400, __("Parameter invalid"));
        }

        // $params['email']
        // $params['number']
        // $params['message'], this was parsed to HtmlThreadBody
        // $params['attachments']
        // $params['close'] 'true' to set ticket as resolved
        $ticket = Ticket::lookupByNumber($params['number']);
        if (empty($ticket) || $ticket->number != $params['number'] || $ticket->getEmail() != $params['email']) {
            return $this->exerr(404, __("Unable to find the ticket"));
        }
        // reference class.thread.php:ThreadEntry::create
        // vars['ip']
        // vars['poster'] or $vars['userId']
        // $vars['message']
        // $vars['attachments']
        $vars = array(
            'ip' => $params['ip'],
            'poster' => $params['email'],
            'message' => $params['message'],
            );
        if (isset($params['attachments'])) {
            $vars['attachments'] = $params['attachments'];
        }
        
        $message = $ticket->postMessage($vars);
        if (empty($message)) {
            return $this->exerr(500, __("Failed to add the message"));
        }

        if ($params['close'] === 'true') {
            $ticket->setStatus(2, 'by postMessage', FALSE);
            $ticket->setAnsweredState(1);
        }

        $this->response(
            201,
            json_encode($message->id)
        );
    }

    /**
    * To response with correct ContentType
    */
    private $_format = 'json';
    function response($code, $resp) {
        $format_to_type = array(
            'email' => 'text/html',
            'json' => 'text/json',
            'xml' => 'text/xml',
            );
        $content_type = $format_to_type[$this->_format];
        // wrap the exerr input
        if ($code >= 400) {
            if (!strcasecmp($this->_format, 'json')) {
                $wrap = array(
                    'error' => $resp,
                    );
                $resp = json_encode($wrap);
            }
            // TODO xml
        }
        Http::response($code, $resp, $content_type);
        exit();
    }

    private function initalizeUser($format) {
      if(!($key=$this->requireApiKey()) || !$key->canGetTicketData())
        return $this->exerr(401, __('API key not authorized'));

      global $ost, $thisstaff;
      $params = $this->getRequest($format);

      $thisstaff = StaffAuthenticationBackend::process(
        $params['user'],
        $params['passwd'], $errors
      );
      if(!$thisstaff->id)
        return $this->exerr(401, __('API user not found'));

    }



}

//Local email piping controller - no API key required!
class PipeApiController extends TicketApiController {

    //Overwrite grandparent's (ApiController) response method.
    function response($code, $resp) {

        //Use postfix exit codes - instead of HTTP
        switch($code) {
            case 201: //Success
                $exitcode = 0;
                break;
            case 400:
                $exitcode = 66;
                break;
            case 401: /* permission denied */
            case 403:
                $exitcode = 77;
                break;
            case 415:
            case 416:
            case 417:
            case 501:
                $exitcode = 65;
                break;
            case 503:
                $exitcode = 69;
                break;
            case 500: //Server error.
            default: //Temp (unknown) failure - retry
                $exitcode = 75;
        }

        //echo "$code ($exitcode):$resp";
        //We're simply exiting - MTA will take care of the rest based on exit code!
        exit($exitcode);
    }

    function  process() {
        $pipe = new PipeApiController();
        if(($ticket=$pipe->processEmail()))
           return $pipe->response(201, $ticket->getNumber());

        return $pipe->exerr(416, __('Request failed - retry again!'));
    }
}

?>
