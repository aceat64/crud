<?php
/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 */

App::uses('CrudListener', 'Crud.Controller/Crud');

/**
 * PublicApiListener
 *
 * Listener to format data consitent with most public
 * APIs out there such as Twitter, GitHub and Google.
 *
 * - https://dev.twitter.com/docs/api/1.1/get/statuses/mentions_timeline
 * - http://developer.github.com/v3/
 * - https://developers.google.com/custom-search/v1/using_rest
 */
class ApiTransformationListener extends CrudListener {

/**
 * Default settings.
 *
 * @var array
 */
	protected $_settings = array(
		'apiOnly' => true,
		'changeNesting' => true,
		'changeKeys' => true,
		'changeTime' => true,
		'castNumbers' => true,

		'_keyMethods' => array(),
		'_valueMethods' => array()
	);

/**
 * Adds a new beforeRender event to the list of events from the
 * ApiListener.
 *
 * @return array
 */
	public function implementedEvents() {
		if ($this->_settings['apiOnly'] && !$this->_request()->is('api')) {
			return array();
		}
		return array('Controller.beforeRender' => 'beforeRender');
	}

/**
 * After everything is done and before anything is rendered change
 * the data format.
 *
 * @return boolean
 */
	public function beforeRender() {
		$viewVars = $this->_controller()->viewVars;
		$viewVar = $this->_action()->viewVar();
		$alias = $this->_model()->alias;

		if (empty($viewVars[$viewVar])) {
			return true;
		}

		$this->_setMethods();

		$data = $viewVars[$viewVar];
		$wrapped = false;

		if (isset($data[$alias])) {
			$data = array($data);
			$wrapped = true;
		}

		$formatted = array();
		foreach ($data as $index => &$record) {
			$new = &$record;
			if ($this->_settings['changeNesting']) {
				$new = $this->_changeNesting($new, $alias);
			}
			unset($data[$index]);
			$this->_recurse($new);
			$formatted[] = $new;
		}
		$formatted = $wrapped ? $formatted[0] : $formatted;

		$this->_controller()->set($viewVar, $formatted);

		return true;
	}

/**
 * Merge in the internal methods based on the settings.
 *
 * @return void
 */
	protected function _setMethods() {
		$keyMethods = $valueMethods = array();

		if ($this->_settings['changeKeys']) {
			$keyMethods[] = '_replaceKeys';
		}

		if ($this->_settings['castNumbers']) {
			$valueMethods[] = '_castNumbers';
		}

		if ($this->_settings['changeTime']) {
			$valueMethods[] = '_changeDateToUnix';
		}

		$this->_settings['_keyMethods'] = array_merge($keyMethods, $this->_settings['_keyMethods']);
		$this->_settings['_valueMethods'] = array_merge($valueMethods, $this->_settings['_valueMethods']);
	}

/**
 * Calls a method. Optimizes where possible because of the
 * large number of calls through this method.
 *
 * @param string|Closure|array $method
 * @param mixed $variable
 * @return mixed
 */
	protected function _call($method, &$variable) {
		if (is_string($method) && method_exists($this, $method)) {
			return $this->$method($variable);
		}

		if ($method instanceof Closure) {
			return $method($variable);
		}

		return call_user_func($method, $variable);
	}

/**
 * Recurse through an array and apply key changes and casts.
 *
 * @param mixed $variable
 * @return void
 */
	protected function _recurse(&$variable) {
		if (is_array($variable)) {
			foreach ($this->_settings['_keyMethods'] as $method) {
				$variable = $this->_call($method, $variable);
			}

			foreach ($variable as &$value) {
				$this->_recurse($value);
			}

			return;
		}

		foreach ($this->_settings['_valueMethods'] as $method) {
			$variable = $this->_call($method, $variable);
		}
	}

/**
 * Nests the secundary models in the array of the
 * primary model.
 *
 * @param array $record
 * @param string $primaryAlias
 * @return array
 */
	protected function _changeNesting(array $record, $primaryAlias) {
		$new = $record[$primaryAlias];
		unset($record[$primaryAlias]);
		$new += $record;
		return $new;
	}

/**
 * Replaces array keys for associated records.
 *
 * Example
 * =======
 *
 * Replacing the array keys for the following associations:
 *
 * User hasMany Comment
 * Comment belongsTo Post
 *
 * The array keys that will replaced:
 *
 * Comment -> comments (plural)
 *   Post -> post (singular)
 *
 * @param array $variable
 * @param string|integer $key
 * @param mixed $value
 * @return void
 */
	protected function _replaceKeys(array $variable) {
		$keys = array_keys($variable);
		$replaced = false;

		foreach ($keys as &$key) {
			if (!is_string($key) || !is_array($variable[$key])) {
				continue;
			}

			$_key = Inflector::tableize($key);
			if (!isset($variable[$key][0])) {
				$_key = Inflector::singularize($_key);
			}

			$key = $_key;
			$replaced = true;
		}

		if (!$replaced) {
			return $variable;
		}

		return array_combine($keys, array_values($variable));
	}

/**
 * Change "1" to 1, and "123.456" to 123.456.
 *
 * @param mixed $variable
 * @return void
 */
	protected function _castNumbers($variable) {
		if (!is_numeric($variable)) {
			return $variable;
		}
		return $variable + 0;
	}

/**
 * Converts database dates to unix times.
 *
 * @param mixed $variable
 * @return integer
 */
	protected function _changeDateToUnix($variable) {
		if (!is_string($variable)) {
			return $variable;
		}

		if (!preg_match('@^\d{4}-\d{2}-\d{2}@', $variable)) {
			return $variable;
		}

		return strtotime($variable);
	}
}
