<?php

use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Elgg\Http\DatabaseSessionHandler;
use Elgg\Config;
use Elgg\Database;

/**
 * Elgg Session Management
 *
 * Reserved keys: last_forward_from, msg, sticky_forms, user, guid, id, code, name, username
 *
 * @see elgg_get_session()
 */
class ElggSession {

	/**
	 * @var SessionInterface
	 */
	protected $storage;

	/**
	 * @var \ElggUser|null
	 */
	protected $logged_in_user;

	/**
	 * @var bool
	 */
	protected $ignore_access = false;

	/**
	 * Constructor
	 *
	 * @param SessionInterface $storage The underlying Session implementation
	 * @access private Use elgg_get_session()
	 */
	public function __construct(SessionInterface $storage) {
		$this->storage = $storage;
	}

	/**
	 * Start the session
	 *
	 * @return boolean
	 * @throws RuntimeException If session fails to start.
	 * @since 1.9
	 */
	public function start() {
		$result = $this->storage->start();
		$this->generateSessionToken();
		return $result;
	}

	/**
	 * Migrates the session to a new session id while maintaining session attributes
	 *
	 * @param boolean $destroy Whether to delete the session or let gc handle clean up
	 * @return boolean
	 * @since 1.9
	 */
	public function migrate($destroy = false) {
		return $this->storage->migrate($destroy);
	}

	/**
	 * Invalidates the session
	 *
	 * Deletes session data and session persistence. Starts a new session.
	 *
	 * @return boolean
	 * @since 1.9
	 */
	public function invalidate() {
		$this->storage->clear();
		$this->logged_in_user = null;
		$result = $this->migrate(true);
		$this->generateSessionToken();
		return $result;
	}

	/**
	 * Has the session been started
	 *
	 * @return boolean
	 * @since 1.9
	 */
	public function isStarted() {
		return $this->storage->isStarted();
	}

	/**
	 * Get the session ID
	 *
	 * @return string
	 * @since 1.9
	 */
	public function getId() {
		return $this->storage->getId();
	}

	/**
	 * Set the session ID
	 *
	 * @param string $id Session ID
	 * @return void
	 * @since 1.9
	 */
	public function setId($id) {
		$this->storage->setId($id);
	}

	/**
	 * Get the session name
	 *
	 * @return string
	 * @since 1.9
	 */
	public function getName() {
		return $this->storage->getName();
	}

	/**
	 * Set the session name
	 *
	 * @param string $name Session name
	 * @return void
	 * @since 1.9
	 */
	public function setName($name) {
		$this->storage->setName($name);
	}

	/**
	 * Get an attribute of the session
	 *
	 * @param string $name    Name of the attribute to get
	 * @param mixed  $default Value to return if attribute is not set (default is null)
	 * @return mixed
	 */
	public function get($name, $default = null) {
		return $this->storage->get($name, $default);
	}

	/**
	 * Set an attribute
	 *
	 * @param string $name  Name of the attribute to set
	 * @param mixed  $value Value to be set
	 * @return void
	 */
	public function set($name, $value) {
		$this->storage->set($name, $value);
	}

	/**
	 * Remove an attribute
	 *
	 * @param string $name The name of the attribute to remove
	 * @return mixed The removed attribute
	 * @since 1.9
	 */
	public function remove($name) {
		return $this->storage->remove($name);
	}

	/**
	 * Has the attribute been defined
	 *
	 * @param string $name Name of the attribute
	 * @return bool
	 * @since 1.9
	 */
	public function has($name) {
		return $this->storage->has($name);
	}

	/**
	 * Sets the logged in user
	 *
	 * @param \ElggUser $user The user who is logged in
	 * @return void
	 * @since 1.9
	 */
	public function setLoggedInUser(\ElggUser $user) {
		$current_user = $this->getLoggedInUser();
		if ($current_user != $user) {
			$this->set('guid', $user->guid);
			$this->logged_in_user = $user;
			_elgg_services()->entityCache->clear();
			_elgg_services()->translator->setCurrentLanguage($user->language);
		}
	}

	/**
	 * Gets the logged in user
	 *
	 * @return \ElggUser|null
	 * @since 1.9
	 */
	public function getLoggedInUser() {
		return $this->logged_in_user;
	}

	/**
	 * Return the current logged in user by guid.
	 *
	 * @see elgg_get_logged_in_user_entity()
	 * @return int
	 */
	public function getLoggedInUserGuid() {
		$user = $this->getLoggedInUser();
		return $user ? $user->guid : 0;
	}
	
	/**
	 * Returns whether or not the viewer is currently logged in and an admin user.
	 *
	 * @return bool
	 */
	public function isAdminLoggedIn() {
		$user = $this->getLoggedInUser();
	
		return $user && $user->isAdmin();
	}
	
	/**
	 * Returns whether or not the user is currently logged in
	 *
	 * @return bool
	 */
	public function isLoggedIn() {
		return (bool) $this->getLoggedInUser();
	}

	/**
	 * Remove the logged in user
	 *
	 * @return void
	 * @since 1.9
	 */
	public function removeLoggedInUser() {
		$this->logged_in_user = null;
		$this->remove('guid');
		_elgg_services()->entityCache->clear();
	}

	/**
	 * Get current ignore access setting.
	 *
	 * @return bool
	 */
	public function getIgnoreAccess() {
		return $this->ignore_access;
	}

	/**
	 * Set ignore access.
	 *
	 * @param bool $ignore Ignore access
	 *
	 * @return bool Previous setting
	 */
	public function setIgnoreAccess($ignore = true) {
		_elgg_services()->accessCache->clear();

		$prev = $this->ignore_access;
		$this->ignore_access = $ignore;

		return $prev;
	}

	/**
	 * Adds a token to the session
	 *
	 * This is used in creation of CSRF token, and is passed to the client to allow validating tokens
	 * later, even if the PHP session was destroyed.
	 *
	 * @return void
	 */
	protected function generateSessionToken() {
		// Generate a simple token that we store server side
		if (!$this->has('__elgg_session')) {
			$this->set('__elgg_session', _elgg_services()->crypto->getRandomString(22));
		}
	}

	/**
	 * Get an isolated ElggSession that does not persist between requests
	 *
	 * @return self
	 */
	public static function getMock() {
		$storage = new MockArraySessionStorage();
		$session = new Session($storage);
		return new self($session);
	}

	/**
	 * Create a session stored in the DB.
	 *
	 * @param Config   $config Config
	 * @param Database $db     Database
	 *
	 * @return ElggSession
	 */
	public static function fromDatabase(Config $config, Database $db) {
		$params = $config->getCookieConfig()['session'];
		$options = [
			// session.cache_limiter is unfortunately set to "" by the NativeSessionStorage
			// constructor, so we must capture and inject it directly.
			'cache_limiter' => session_cache_limiter(),

			'name' => $params['name'],
			'cookie_path' => $params['path'],
			'cookie_domain' => $params['domain'],
			'cookie_secure' => $params['secure'],
			'cookie_httponly' => $params['httponly'],
			'cookie_lifetime' => $params['lifetime'],
		];

		$handler = new DatabaseSessionHandler($db);
		$storage = new NativeSessionStorage($options, $handler);
		$session = new Session($storage);
		return new self($session);
	}

	/**
	 * Create a session stored in files
	 *
	 * @param Config $config Config
	 *
	 * @return ElggSession
	 */
	public static function fromFiles(Config $config) {
		$params = $config->getCookieConfig()['session'];
		$options = [
			// session.cache_limiter is unfortunately set to "" by the NativeSessionStorage
			// constructor, so we must capture and inject it directly.
			'cache_limiter' => session_cache_limiter(),

			'name' => $params['name'],
			'cookie_path' => $params['path'],
			'cookie_domain' => $params['domain'],
			'cookie_secure' => $params['secure'],
			'cookie_httponly' => $params['httponly'],
			'cookie_lifetime' => $params['lifetime'],
		];

		$storage = new NativeSessionStorage($options);
		$session = new Session($storage);
		return new self($session);
	}
}
