<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The library file for the XCache cache store.
 *
 * This file is part of the XCache cache store, it contains the API for interacting with an instance of the store.
 *
 * @package    cachestore_xcache
 * @copyright  2012 Matteo Scaramuccia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The XCache store class.
 *
 * XCache 1.3.1 required
 * @see http://xcache.lighttpd.net/ticket/230
 * @see http://xcache.lighttpd.net/wiki/XcacheApi
 *
 * @copyright  2012 Matteo Scaramuccia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_xcache extends cache_store implements cache_is_key_aware {

    /**
     * The name of the store
     * @var store
     */
    protected $name;

    /**
     * The store instance id (should be unique)
     * @var string
     */
    protected $instanceid = null;

    /**
     * Set to true when things are ready to be initialised.
     * @var bool
     */
    protected $isready = false;

    /**
     * The ttl if there is one. Hopefully not.
     * @var int
     */
    protected $ttl = 0;

    /**
     * Constructs the store instance.
     *
     * Noting that this function is not an initialisation. It is used to prepare the store for use.
     * The store will be initialised when required and will be provided with a cache_definition at that time.
     *
     * @param string $name
     * @param array $configuration
     */
    public function __construct($name, array $configuration = array()) {
        $this->name = $name;
        $this->isready = self::are_requirements_met();
    }

    /**
     * Returns the supported features as a combined int.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_features(array $configuration = array()) {
        return self::SUPPORTS_NATIVE_TTL;
    }

    /**
     * Returns the supported modes as a combined int.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_modes(array $configuration = array()) {
        return self::MODE_APPLICATION + self::MODE_SESSION;
    }

    /**
     * Returns true if the store requirements are met.
     *
     * XCache 1.3.1 or above preferred: required in case of 'xcache.admin.enable_auth' set to 'On'.
     * @see http://xcache.lighttpd.net/ticket/230
     *
     * @return bool
     */
    public static function are_requirements_met() {
        if (!extension_loaded('xcache') ||    // XCache PHP extension is not available.
            !ini_get('xcache.cacher') ||      // XCache cacher is not enabled.
            (ini_get('xcache.var_size') == 0) // Variable data is not enabled.
        ) {
            return false;
        }

        $version = phpversion('xcache');
        if ($version) { // It shouldn't be false at this point
            $xcenableauth = ini_get('xcache.admin.enable_auth');
            // We need XCache v1.3.1 (=> xcache_unset_by_prefix) or
            // 'xcache.admin.enable_auth' set to 'Off', unsecure!
            return version_compare($version, '1.3.1', '>=') || empty($xcenableauth);
        }

        return false;
    }

    /**
     * Returns true if the given mode is supported by this store.
     *
     * @param int $mode One of cache_store::MODE_*
     * @return bool
     */
    public static function is_supported_mode($mode) {
        return ($mode === self::MODE_APPLICATION || $mode === self::MODE_SESSION);
    }

    /**
     * Initialises the cache.
     *
     * Once this has been done the cache is all set to be used.
     *
     * @param cache_definition $definition
     */
    public function initialise(cache_definition $definition) {
        $this->instanceid = $definition->generate_definition_hash();
        $this->ttl = $definition->get_ttl();
    }

    /**
     * Returns true once this instance has been initialised.
     *
     * @return bool
     */
    public function is_initialised() {
        return $this->isready && !empty($this->instanceid);
    }

    /**
     * Returns true if this store instance is ready to be used.
     * @return bool
     */
    public function is_ready() {
        return $this->isready;
    }

    /**
     * Retrieves an item from the cache store given its key.
     *
     * @param string $key The key to retrieve
     * @return mixed The data that was associated with the key, or false if the key did not exist.
     */
    public function get($key) {
        $cachekey = $this->get_store_key($key);
        if (xcache_isset($cachekey)) {
            return xcache_get($cachekey);
        }

        return false;
    }

    /**
     * Retrieves several items from the cache store in a single transaction.
     *
     * If not all of the items are available in the cache then the data value for those that are missing will be set to false.
     *
     * @param array $keys The array of keys to retrieve
     * @return array An array of items from the cache. There will be an item for each key, those that were not in the store will
     *      be set to false.
     */
    public function get_many($keys) {
        $return = array();
        foreach ($keys as $key) {
            $return[$key] = $this->get($key);
        }

        return $return;
    }

    /**
     * Sets an item in the cache given its key and data value.
     *
     * @param string $key The key to use.
     * @param mixed $data The data to set.
     * @return bool True if the operation was a success false otherwise.
     */
    public function set($key, $data) {
        return xcache_set($this->get_store_key($key), $data, $this->ttl);
    }

    /**
     * Sets many items in the cache in a single transaction.
     *
     * @param array $keyvaluearray An array of key value pairs. Each item in the array will be an associative array with two
     *      keys, 'key' and 'value'.
     * @return int The number of items successfully set. It is up to the developer to check this matches the number of items
     *      sent ... if they care that is.
     */
    public function set_many(array $keyvaluearray) {
        $count = 0;
        foreach ($keyvaluearray as $pair) {
            if ($this->set($pair['key'], $pair['value'])) {
                $count++;
            }
        }

        return $count;
    }


    /**
     * Checks if the store has a record for the given key and returns true if so.
     *
     * @param string $key
     * @return bool
     */
    public function has($key) {
        return xcache_isset($this->get_store_key($key));
    }

    /**
     * Returns true if the store contains records for all of the given keys.
     *
     * @param array $keys
     * @return bool
     */
    public function has_all(array $keys) {
        foreach ($keys as $key) {
            if (!$this->has($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true if the store contains records for any of the given keys.
     *
     * @param array $keys
     * @return bool
     */
    public function has_any(array $keys) {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes an item from the cache store.
     *
     * @param string $key The key to delete.
     * @return bool Returns true if the operation was a success, false otherwise.
     */
    public function delete($key) {
        return xcache_unset($this->get_store_key($key));
    }

    /**
     * Deletes several keys from the cache in a single action.
     *
     * @param array $keys The keys to delete
     * @return int The number of items successfully deleted.
     */
    public function delete_many(array $keys) {
        $count = 0;
        foreach ($keys as $key) {
            if ($this->delete($key)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Purges the XCache cacher deleting all items matching the given prefix.
     *
     * XCache v1.3.1 will let this action to be as fast as possible.
     */
    protected function unset_by_prefix($prefix) {
        // Just flush the definition, if possible => XCache v1.3.1+, http://xcache.lighttpd.net/ticket/230
        if (function_exists('xcache_unset_by_prefix')) {
            return xcache_unset_by_prefix($prefix);
        } else { // Otherwise, perform it within the PHP code below i.e. actually a slow process!
            // 'xcache.admin.enable_auth' must be set to 'Off'. Please note that it has been declared
            // as PHP_INI_SYSTEM, http://xcache.lighttpd.net/wiki/XcacheIni#XCacheAdministration
            // so there's no chance to programmatically change it .
            $xcenableauth = ini_get('xcache.admin.enable_auth');
            if (empty($xcenableauth)) {
                $vcnt = xcache_count(XC_TYPE_VAR);
                for ($vc = 0; $vc < $vcnt; $vc++) {
                    $xcachelistvar = xcache_list(XC_TYPE_VAR, $vc);
                    foreach ($xcachelistvar['cache_list'] as $entry) {
                        // Found the matching (= starts with our prefix) entry and delete it
                        if (strpos($entry['name'], $prefix, 0) === 0) {
                            xcache_unset($entry['name']);
                        }
                    }
                }
            }
        }
    }

    /**
     * Purges the cache deleting all items within it.
     *
     * @return boolean True on success. False otherwise.
     */
    public function purge() {
        if ($this->isready) {
            $this->unset_by_prefix($this->get_prefix());
        }

        return true;
    }

    /**
     * Performs any necessary clean up when the store instance is being deleted.
     */
    public function cleanup() {
        if ($this->isready) {
            $this->unset_by_prefix($this->get_store_id());
        }
    }

    /**
     * Performs any necessary operation when the store instance is being deleted,
     * regardless the store being initialised with a definition ({@link initialise()}).
     *
     * @link http://tracker.moodle.org/browse/MDL-36363
     * @see cleanup()
     */
    public function instance_deleted() {
        $this->cleanup();
    }

    /**
     * Generates an instance of the cache store that can be used for testing.
     *
     * @param cache_definition $definition
     * @return false
     */
    public static function initialise_test_instance(cache_definition $definition) {
        $cache = new cachestore_xcache('XCache store');
        $cache->initialise($definition);
        return $cache;
    }

    /**
     * Returns the name of this instance.
     * @return string
     */
    public function my_name() {
        return $this->name;
    }

    /**
     * Return a system identifier for the running Moodle instance
     * @return string
     */
    protected function get_store_id() {
        global $CFG;
        return md5("$CFG->wwwroot $this->name") . '__';
    }

    /**
     * Return the prefix for the given definition
     * @return string
     */
    protected function get_prefix() {
        return $this->get_store_id() . $this->instanceid . '__';
    }

    /**
     * Return the prefix for the given definition
     * @return string
     */
    protected function get_store_key($key) {
        return $this->get_prefix() . $key;
    }
}