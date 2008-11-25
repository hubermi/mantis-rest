<?php
	$config = parse_ini_file('mantis_rest.ini', TRUE);
	require_once($config['mantis']['root'] . '/core.php');

	function __autoload($class)
	{
		$class = strtolower($class);

		foreach (array("resources", "http") as $d) {
			if (file_exists("$d/$class.class.php")) {
				require_once("$d/$class.class.php");
				return;
			}
		}
	}

	function get_config()
	{
		global $config;
		return $config;
	}

	function method_not_allowed($method, $allowed)
	{
		/**
		 * 	Errors out when a method is not allowed on a resource.
		 *
		 * 	We set the Allow header, which is a MUST according to RFC 2616.
		 *
		 * 	@param $method - The method that's not allowed
		 * 	@param $allowed - An array containing the methods that are allowed
		 */
		throw new HTTPException(405, "The method $method can't be used on this resource",
			array("allow: " . implode(", ", $allowed)));
	}

	function get_string_to_enum($enum_string, $string)
	{
		/**
		 * 	Gets Mantis's integer for the given string
		 *
		 * 	This is the inverse of Mantis's get_enum_to_string().  If the string is
		 * 	not found in the enum string, we return -1.
		 */
		if (preg_match('/^@.*@$/', $string)) {
			return substr($string, 1, -1);
		}
		$enum_array = explode_enum_string($enum_string);
		foreach ($enum_array as $pair) {
			$t_s = explode_enum_arr($pair);
			if ($t_s[1] == $string) {
				return $t_s[0];
			}
		}
		return -1;
	}

	function date_to_timestamp($iso_date)
	{
		/**
		 *	Returns a UNIX timestamp for the given date.
		 *
		 *	@param $iso_date - A string containing a date in ISO 8601 format
		 */
		return strtotime($iso_date);
	}

	function timestamp_to_iso_date($timestamp)
	{
		/**
		 *	Returns an ISO 8601 date for the given timestamp.
		 *
		 *	@param $timestamp - The timestamp.
		 */
		return date('c', $timestamp);
	}

	function date_to_iso_date($date)
	{
		return date('c', strtotime($date));
	}

	function date_to_sql_date($date)
	{
		return date('Y-m-d H:i:s', strtotime($date));
	}

	function handle_error($errno, $errstr)
	{
		throw new HTTPException(500, "Mantis encountered an error: " . error_string($errstr));
		$resp->send();
		exit;
	}
	set_error_handler("handle_error", E_USER_ERROR);

	class HTTPException extends Exception
	{
		function __construct($status, $message, $headers=NULL)
		{
			$this->resp = new Response();
			$this->resp->status = $status;
			$this->resp->body = $message;
			if (!is_null($headers)) {
				$this->resp->headers = $headers;
			}
		}
	}

	abstract class Resource
	{
		/**
		 * 	A REST resource; the abstract for all resources we serve.
		 */
		public function repr($request)
		{
			/**
			 * 	Returns a representation of resource.
			 *
			 * 	@param $request - The request we're answering
			 */
			$type = $request->type_expected;
			if ($type == 'text/x-json' || $type == 'application/json') {
				return json_encode($this->rsrc_data);
			} else {
				return '';
			}
		}

		protected function _build_sql_from_querystring($qs)
		{
			/**
			 * 	Returns a string of SQL to tailor a query to the given query string.
			 *
			 * 	Resource lists use this function to filter, order, and limit their
			 * 	result sets.  It calls $this->_get_query_condition() and
			 * 	$this->_get_query_sort(), so both must exist.
			 *
			 * 	@param $qs - The query string
			 */
			$qs_pairs = array();
			parse_str($qs, $qs_pairs);

			$filter_pairs = array();
			$sort_pairs = array();
			$limit = 0;
			foreach ($qs_pairs as $k => $v) {
				if (strpos($k, 'sort-') === 0) {
					$sort_pairs[$k] = $v;
				} elseif ($k == 'limit') {
					$limit = (int)$v;
					if ($limit < 0) {
						throw new HTTPException(500,
							"Result limit must be nonnegative.");
					}
				} else {
					$filter_pairs[$k] = $v;
				}
			}

			$conditions = array();
			$orders = array();
			$limit_statement = "";
			foreach ($filter_pairs as $k => $v) {
				$condition = $this->_get_query_condition($k, $v);
				if ($condition) {
					$conditions[] = $condition;
				}
			}
			foreach ($sort_pairs as $k => $v) {
				$k = substr($k, 5);	# Strip off the 'sort-'
				$orders[] = $this->_get_query_order($k, $v);
			}
			if ($limit) {
				$limit_statement = "LIMIT $limit";
			}

			$sql = "";
			if ($conditions) {
				$sql .= ' WHERE (';
				$sql .= implode(') AND (', $conditions);
				$sql .= ')';
			}
			if ($orders) {
				$sql .= ' ORDER BY ';
				$sql .= implode(', ', $orders);
			}
			if ($limit) {
				$sql .= " LIMIT $limit";
			}
			return $sql;
		}

		abstract public function get($request);	# Handles a GET request for the resource
		abstract public function put($request);	# Handles a PUT request
		abstract public function post($request);# Handles a POST request
	}

	class RestService
	{
		/**
		 * 	A REST service.
		 */
		public function handle($request)
		{
			/**
			 * 	Handles the resource request.
			 *
			 * 	@param $request - A Request object
			 * 	@param $return_response - If given, we return the Response object
			 * 		instead of sending it.
			 */
			if (!auth_attempt_script_login($request->username, $request->password)) {
				throw new HTTPException(401, "Invalid credentials", array(
					'WWW-Authenticate: Basic realm="Mantis REST API"'));
			}

			$path = $request->rsrc_path;
			if (preg_match('!^/users/?$!', $path)) {
				$resource = new UserList($request->url);
			} elseif (preg_match('!^/users/\d+/?$!', $path)) {
				$resource = new User($request->url);
			} elseif (preg_match('!^/bugs/?$!', $path)) {
				$resource = new BugList($request->url);
			} elseif (preg_match('!^/bugs/\d+/?$!', $path)) {
				$resource = new Bug($request->url);
			} elseif (preg_match('!^/bugs/\d+/notes/?$!', $path)) {
				$resource = new BugnoteList($request->url);
			} elseif (preg_match('!^/notes/\d+/?$!', $path)) {
				$resource = new Bugnote($request->url);
			} else {
				throw new HTTPException(404, "No resource at this URL");
			}

			if ($request->method == 'GET') {
				$resp = $resource->get($request);
			} elseif ($request->method == 'PUT') {
				$resp = $resource->put($request);
			} elseif ($request->method == 'POST') {
				$resp = $resource->post($request);
			} else {
				throw new HTTPException(501, "Unrecognized method: $request->method");
			}

			return $resp;
		}
	}
