<?php
if (!function_exists('pr')) {
	/**
	 * Shortcut function for quickly debugging data.
	 *
	 * @param mixed $arr
	 */
    function pr($arr) {
		return debug($arr);
		// or:
        if (is_array($arr) && count($arr)) {
            print_r($arr);
        } else {
            var_dump($arr);
        }
        echo "\n";
    }
}
if (!function_exists('prd')) {
	/**
	 * Shortcut function for quickly debugging data, then dying.
	 *
	 * @param mixed $arr
	 */
    function prd($arr) {
        pr($arr);
        die();
    }
}
Class RestComponent extends Object {
	public $codes = array(
		200 => 'OK',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Time-out',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Time-out',
	);

	public $Controller;
	public $postData;

	protected $_RestLog;
	protected $_logData = array();
	protected $_feedback = array();
	protected $_credentials = array();

	protected $_settings = array(
		// Component options
		'callbacks' => array(
			'cbRestlogBeforeSave' => 'restlogBeforeSave',
			'cbRestlogAfterSave' => 'restlogAfterSave',
			'cbRestlogBeforeFind' => 'restlogBeforeFind',
			'cbRestlogAfterFind' => 'restlogAfterFind',
			'cbRestRatelimitMax' => 'restRatelimitMax',
		),
		'extensions' => array('xml', 'json'),
		'viewsFromPlugin' => true,
		'skipControllers' => array(
			'App',
			'Defaults',
		),
		'auth' => array(
			'requireSecure' => false,
			'keyword' => 'TRUEREST',
			'fields' => array(
				'class' => 'class',
				'apikey' => 'apikey',
				'username' => 'username',
			),
		),
		'log' => array(
			'model' => 'Rest.RestLog',
			'dump' => true,
		),
		'ratelimit' => array(
			'classlimits' => array(
				'Employee' => array('-1 hour', 1000),
				'Customer' => array('-1 hour', 100),
			),
			'identfield' => 'apikey',
		),

		// Both Helper & Component options
		'debug' => 0,

		// Passed as Helper options
		'view' => array(
			'extract' => array(),
		),
	);


	public function initialize (&$Controller, $settings = array()) {
		$this->Controller = $Controller;
		$this->_settings  = am($this->_settings, $settings);

		// Control Debug First
		$this->_settings['debug'] = (int)$this->_settings['debug'];
		Configure::write('debug', $this->_settings['debug']);
		$this->Controller->set('debug', $this->_settings['debug']);

		if (!$this->isActive()) {
			return;
		}

		// Set credentials
		$this->credentials(true);

		// Prepare log
		$this->log(array(
			'controller' => $this->Controller->name,
			'action' => $this->Controller->action,
			'model_id' => @$this->Controller->passedArgs[0]
				? @$this->Controller->passedArgs[0]
				: 0,
			'ratelimited' => 0,
			'requested' => date('Y-m-d H:i:s'),
			'ip' => $_SERVER['REMOTE_ADDR'],
			'httpcode' => 200,
		));

		// Validate & Modify Post
		$this->postData = $this->_modelizePost($this->Controller->data);
		if ($this->postData === false) {
			return $this->abort('Invalid post data');
		}

		// SSL
		if (!empty($this->_settings['auth']['requireSecure'])) {
			if (!isset($this->Controller->Security)
				|| !is_object($this->Controller->Security)) {
				return $this->abort('You need to enable the Security component first');
			}
			$this->Controller->Security->requireSecure($this->_settings['auth']['requireSecure']);
		}

		// Set content-headers
		$this->headers();
	}

	/**
	 * Catch & fire callbacks. You can map callbacks to different places
	 * using the value parts in $this->_settings['callbacks'].
	 * If the resolved callback is a string we assume it's in
	 * the controller.
	 *
	 * @param string $name
	 * @param array  $arguments
	 */
	public function  __call ($name, $arguments) {
		if (!isset($this->_settings['callbacks'][$name])) {
			return $this->abort('Function does not exist: '. $name);
		}

		$cb = $this->_settings['callbacks'][$name];
		if (is_string($cb)) {
			$cb = array($this->Controller, $cb);
		}

		if (is_callable($cb)) {
			array_unshift($arguments, $this);
			return call_user_func_array($cb, $arguments);
		}
	}


	/**
	 * Write the accumulated logentry
	 *
	 * @param <type> $Controller
	 */
	public function shutdown (&$Controller) {
		$this->log(array(
			'responded' => date('Y-m-d H:i:s'),
		));

		$this->log(true);
	}

	/**
	 * Controls layout & view files
	 *
	 * @param <type> $Controller
	 * @return <type>
	 */
	public function startup (&$Controller) {
		if (!$this->isActive()) {
			return;
		}

		// Rate Limit
		$credentials = $this->credentials();
		$class	     = @$credentials['class'];
		if (!$class) {
			$this->warning('Unable to establish class');
		} else {
			list($time, $max) = $this->_settings['ratelimit']['classlimits'][$class];

			$cbMax = $this->cbRestRatelimitMax($credentials);
			if ($cbMax) {
				$max = $cbMax;
			}

			if (true !== ($count = $this->ratelimit($time, $max))) {
				$msg = sprintf(
					'You have reached your ratelimit (%s is more than the allowed %s requests in %s)',
					$count,
					$max,
					str_replace('-', '', $time)
				);
				$this->log('ratelimited', 1);
				return $this->abort($msg);
			}
		}

		if ($this->_settings['viewsFromPlugin']) {
			// Setup the controller so it can use
			// the view inside this plugin
			$this->Controller->view = 'Rest.' . $this->View(false);
		}
	}

	/**
	 * Collects viewVars, reformats, and makes them available as
	 * viewVar: response for use in REST serialization
	 *
	 * @param <type> $Controller
	 *
	 * @return <type>
	 */
	public function beforeRender (&$Controller) {
		if (!$this->isActive()) return;

		$data = $this->inject(
			(array)@$this->_settings[$this->Controller->action]['extract'],
			$this->Controller->viewVars
		);

		$response = $this->response($data);

		$this->Controller->set(compact('response'));
	}

	/**
	 * Determines is an array is numerically indexed
	 *
	 * @param array $array
	 *
	 * @return boolean
	 */
	public function numeric ($array = array()) {
		if (empty($array)) {
			return null;
		}
		$keys = array_keys($array);
		foreach ($keys as $key) {
			if (!is_numeric($key)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Prepares REST data for cake interaction
	 *
	 * @param <type> $data
	 * @return <type>
	 */
	protected function _modelizePost (&$data) {
		if (!is_array($data)) {
			return $data;
		}

		// Protected against Saving multiple models in one post
		// while still allowing mass-updates in the form of:
		// $this->data[1][field] = value;
		if (Set::countDim($data) === 2) {
			if (!$this->numeric($data)) {
				return $this->error('2 dimensional can only begin with numeric index');
			}
		} else if (Set::countDim($data) !== 1) {
			return $this->error('You may only send 1 dimensional posts');
		}

		// Encapsulate in Controller Model
		$data = array(
			$this->Controller->modelClass => $data,
		);

		return $data;
	}

	/**
	 * Works together with Logging to ratelimit incomming requests by
	 * identfield
	 *
	 * @return <type>
	 */
	public function ratelimit ($time, $max) {
		// No rate limit active
		if (empty($this->_settings['ratelimit'])) {
			return true;
		}

		// Need logging
		if (empty($this->_settings['log']['model'])) {
			return $this->abort('Logging is required for any ratelimiting '.
				'to work');
		}

		// Need identfield
		if (empty($this->_settings['ratelimit']['identfield'])) {
			return $this->abort('Need a identfield or I will not know what to '.
				'ratelimit on');
		}

		$identField = $this->_settings['ratelimit']['identfield'];

		$this->cbRestlogBeforeFind();
		$logs = $this->RestLog()->find('list', array(
			'fields' => array('id', $identField),
			'conditions' => array(
				'requested >' => date('Y-m-d H:i:s', strtotime($time)),
				$identField => $this->credentials($identField),
			),
		));
		$this->cbRestlogAfterFind();
		$count = count($logs);
		if ($count >= $max) {
			return $count;
		}

		return true;
	}

	/**
	 * Return an instance of the log model
	 *
	 * @return object
	 */
	public function RestLog () {
		if (!$this->_RestLog) {
			$this->_RestLog = ClassRegistry::init($this->_settings['log']['model']);
		}

		return $this->_RestLog;
	}

	/**
	 * log(true) writes log to disk. otherwise stores key-value
	 * pairs in memory for later saving. Can also work recursively
	 * by giving an array as the key
	 *
	 * @param mixed $key
	 * @param mixed $val
	 *
	 * @return boolean
	 */
	public function log ($key, $val = null) {
		// Write log
		if ($key === true && func_num_args() === 1) {
			if (!@$this->_settings['log']['model']) {
				return true;
			}

			$this->RestLog()->create();
			$this->cbRestlogBeforeSave();
			$res = $this->RestLog()->save(array(
				$this->RestLog()->alias => $this->_logData,
			));
			$this->cbRestlogAfterSave();

			return $res;
		}

		// Multiple values: recurse
		if (is_array($key)) {
			foreach($key as $k=>$v) {
				$this->log($k, $v);
			}
			return true;
		}

		// Single value, save
		$this->_logData[$key] = $val;
		return true;
	}

	/**
	 * Sets or returns credentials as found in the 'Authorization' header
	 * sent by the client.
	 *
	 * Have your client set a header like:
	 * Authorization: TRUEREST username=john&password=xxx&apikey=247b5a2f72df375279573f2746686daa<
	 * http://docs.amazonwebservices.com/AmazonS3/2006-03-01/index.html?RESTAuthentication.html
	 *
	 * credentials(true) sets credentials
	 * credentials() returns full array
	 * credentials('username') returns username
	 *
	 * @param mixed boolean or string $set
	 *
	 * @return <type>
	 */
	public function credentials ($set = false) {
		// Return full credentials
		if ($set === false) {
			return $this->_credentials;
		}

		// Set credentials
		if ($set === true) {
			if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
				$parts = explode(' ', $_SERVER['HTTP_AUTHORIZATION']);
				$match = array_shift($parts);
				if ($match !== $this->_settings['auth']['keyword']) {
					return false;
				}
				$str = join(' ', $parts);
				parse_str($str, $this->_credentials);

				$this->log(array(
					'username' => $this->_credentials[$this->_settings['auth']['fields']['username']],
					'apikey' => $this->_credentials[$this->_settings['auth']['fields']['apikey']],
					'class' => $this->_credentials[$this->_settings['auth']['fields']['class']],
				));
			}

			return $this->_credentials;
		}

		// Return 1 field
		if (is_string($set)) {
			// First try key as is
			if (null !== ($val = @$this->_credentials[$set])) {
				return $val;
			}

			// Fallback to the mapped key according to authfield settings
			if (null !== ($val = @$this->_credentials[$this->_settings['auth']['fields'][''.$set]])) {
				return $val;
			}

			return null;
		}

		return $this->abort('credential argument not supported');
	}

	/**
	 * Returns a list of Controllers where Rest component has been activated
	 * uses Cache::read & Cache::write by default to tackle performance
	 * issues.
	 *
	 * @param boolean $cached
	 *
	 * @return array
	 */
	public function controllers ($cached = true) {
		$ckey = sprintf('%s.%s', __CLASS__, __FUNCTION__);

		if (!$cached || !($restControllers = Cache::read($ckey))) {
			$restControllers = array();

			if (method_exists('App', 'objects')) {
				// As of cake 1.3, use App::objects instead of Configure::listObjects
				// http://code.cakephp.org/wiki/1.3/migration-guide
				$controllers = App::objects('controller', null, false);
			} else {
				$controllers = Configure::listObjects('controller', null, false);
			}

			// Unlist some controllers by default
			foreach ($this->_settings['skipControllers'] as $skipController) {
				if (false !== ($key = array_search($skipController, $controllers))) {
					unset($controllers[$key]);
				}
			}

			// Instantiate all remaining controllers and check components
			foreach ($controllers as $controller) {
				$className = $controller.'Controller';
				if (!class_exists($className)) {
					if (!App::import('Controller', $controller)) {
						continue;
					}
				}
				$Controller = new $className();

				if (isset($Controller->components['Rest.Rest'])
					|| in_array('Rest.Rest', $Controller->components)) {

					$restControllers[] = $controller;
				}
				unset($Controller);
			}

			sort($restControllers);

			if ($cached) {
				Cache::write($ckey, $restControllers);
			}
		}

		return $restControllers;
	}

	/**
	 * Set content-type headers based on extension
	 *
	 * @param <type> $ext
	 *
	 * @return <type>
	 */
	public function headers ($ext = false) {
		if (!$ext) {
			$ext = $this->Controller->params['url']['ext'];
		}

		// Don't know why,  but RequestHandler isn't settings
		// Content-Type right;  so using header() for now instead
		switch($ext) {
			case 'json':
				// text/javascript
				// application/json
				if ($this->_settings['debug'] < 3) {
					header('Content-Type: text/javascript');
					$this->Controller->RequestHandler->setContent('json', 'text/javascript');
					$this->Controller->RequestHandler->respondAs('json');
				}
				break;
			case 'xml':
				if ($this->_settings['debug'] < 3) {
					header('Content-Type: text/xml');
					$this->Controller->RequestHandler->setContent('xml', 'text/xml');
					$this->Controller->RequestHandler->respondAs('xml');
				}
				break;
			default:
				return $this->abort(
					sprintf('Unsupported extension: "%s"', $ext),
					E_USER_ERROR
				);
				break;
		}
	}

	public function isActive () {
		static $isActive;
		if (!isset($isActive)) {
			$isActive = in_array(
				$this->Controller->params['url']['ext'],
				$this->_settings['extensions']
			);
		}
		return $isActive;
	}

	public function error ($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();
		if (count($args) > 1) $format = vsprintf($format, $args);
		$this->_feedback[__FUNCTION__][] = $format;
		return false;
	}
	public function info ($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();
		if (count($args) > 1) $format = vsprintf($format, $args);
		$this->_feedback[__FUNCTION__][] = $format;
		return true;
	}
	public function warning ($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();
		if (count($args) > 1) $format = vsprintf($format, $args);
		$this->_feedback[__FUNCTION__][] = $format;
		return false;
	}

	/**
	 * Returns (optionally) formatted feedback.
	 *
	 * @param boolean $format
	 *
	 * @return array
	 */
	public function getFeedBack ($format = false) {
		if (!$format) {
			return $this->_feedback;
		}

		$feedback = array();
		foreach ($this->_feedback as $level=>$messages) {
			foreach ($messages as $i=>$message) {
				$feedback[] = array(
					'message' => $message,
					'level' => $level,
				);
			}
		}

		return $feedback;
	}

	/**
	 * Reformats data according to Xpaths in $take
	 *
	 * @param array $take
	 * @param array $viewVars
	 *
	 * @return array
	 */
	public function inject ($take, $viewVars) {
		$data = array();
		foreach ($take as $path => $dest) {
			if (is_numeric($path)) {
				$path = $dest;
			}

			$data = Set::insert($data, $dest, Set::extract($path, $viewVars));
		}

		return $data;
	}

	/**
	 * Get an array of everything that needs to go into the Xml / Json
	 *
	 * @param array $data optional. Data collected by cake
	 *
	 * @return array
	 */
	public function response ($data = array()) {
		$feedback   = $this->getFeedBack(true);

		$serverKeys = array_flip(array(
			'HTTP_HOST',
			'HTTP_USER_AGENT',
			'REMOTE_ADDR',
			'REQUEST_METHOD',
			'REQUEST_TIME',
			'REQUEST_URI',
			'SERVER_ADDR',
			'SERVER_PROTOCOL',
		));
		$server = array_intersect_key($_SERVER, $serverKeys);
		foreach ($server as $k=>$v) {
			if ($k === ($lc = strtolower($k))) {
				continue;
			}
			$server[$lc] = $v;
			unset($server[$k]);
		}

		// In case of edit, return what post data was received
		if (empty($data) && !empty($this->postData)) {
			$data = $this->postData;
		}

		$status = count(@$this->_feedback['error'])
			? 'error'
			: 'ok';

		$response = array(
			'meta' => array(
				'status' => $status,
				'feedback' => $feedback,
				'request' => $server,
				'credentials' => array(),
			),
			'data' => $data,
		);

		foreach ($this->_settings['auth']['fields'] as $field) {
			$response['meta']['credentials'][$field] = $this->credentials($field);
		}

		if (!empty($this->_settings['log']['dump'])) {
			$this->log(array(
				'meta' => json_encode($response['meta']),
				'data_in' => json_encode($this->postData),
				'data_out' => json_encode($response['data']),
			));
		}

		return $response;
	}

	/**
	 * Returns either string or reference to active View object
	 *
	 * @param boolean $object
	 * @param string  $ext
	 *
	 * @return mixed object or string
	 */
	public function View ($object = true, $ext = null) {
		if (!$this->isActive()) {
			return $this->abort('Rest not activated. Maybe try correct extension.');
		}

		if ($ext === null) {
			$ext = $this->Controller->params['url']['ext'];
		}

		$base = Inflector::camelize($ext);
		if (!$object) {
			return $base;
		}

		$className = $base .'View';

		if (!class_exists($className)) {
			$pluginRoot = dirname(dirname(dirname(__FILE__)));
			require_once $pluginRoot . '/views/' . $ext . '.php';
		}

		$View      = ClassRegistry::init('Rest.'.$className);
		if (empty($View->params)) {
			$View->params = $this->Controller->params;
		}

		
		return $View;
	}

	/**
	 * Should be called by Controller->redirect to dump
	 * an error & stop further execution.
	 *
	 * @param <type> $params
	 * @param <type> $data
	 */
	public function abort ($params = array(), $data = array()) {
		if (is_string($params)) {
			$code  = '403';
			$error = $params;
		} else {
			$code  = '200';
			$error = '';

			if (is_object($this->Controller->Session) && @$this->Controller->Session->read('Message.auth')) {
				// Automatically fetch Auth Component Errors
				$code  = '403';
				$error = $this->Controller->Session->read('Message.auth.message');
			}

			if (!empty($params['status'])) {
				$code = $params['status'];
			}
			if (!empty($params['status'])) {
				$error = $params['error'];
			}
		}
		if ($error) {
			$this->error($error);
		}
		$this->Controller->header(sprintf('HTTP/1.1 %s %s', $code, $this->codes[$code]));

		$this->headers();
		$encoded = $this->View()->encode($this->response($data));

		// Die.. ugly. but very safe. which is what we need
		// or all Auth & Acl work could be circumvented
		$this->log(array(
			'httpcode' => $code,
			'error' => $error,
		));
		$this->shutdown($this->Controller);
		die($encoded);
	}
}