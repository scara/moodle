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
 * Native MariaDB class representing moodle database interface.
 *
 * @package    core_dml
 * @copyright  2013 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/moodle_database.php');
require_once(__DIR__.'/mysqli_native_moodle_database.php');
require_once(__DIR__.'/mysqli_native_moodle_recordset.php');
require_once(__DIR__.'/mysqli_native_moodle_temptables.php');

/**
 * Native MariaDB class representing moodle database interface.
 *
 * @package    core_dml
 * @copyright  2013 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mariadb_native_moodle_database extends mysqli_native_moodle_database {

    /** @var string DB server actual version */
    protected $serverversion = null;

    /**
     * Returns localised database type name
     * Note: can be used before connect()
     * @return string
     */
    public function get_name() {
        return get_string('nativemariadb', 'install');
    }

    /**
     * Returns localised database configuration help.
     * Note: can be used before connect()
     * @return string
     */
    public function get_configuration_help() {
        return get_string('nativemariadbhelp', 'install');
    }

    /**
     * Returns the database vendor.
     * Note: can be used before connect()
     * @return string The db vendor name, usually the same as db family name.
     */
    public function get_dbvendor() {
        return 'mariadb';
    }

    /**
     * Returns more specific database driver type
     * Note: can be used before connect()
     * @return string db type mysqli, pgsql, oci, mssql, sqlsrv
     */
    protected function get_dbtype() {
        return 'mariadb';
    }

    /**
     * Returns database server info array
     * @return array Array containing 'description' and 'version' info
     */
    public function get_server_info() {
        $version = $this->serverversion;
        if (empty($version)) {
            // Initial fallback: the version returned by the PHP client.
            // Note: it will be prefixed by the RPL_VERSION_HACK starting from 10.x, when not using an authentication plug-in.
            $version = $this->mysqli->server_info;
            // Try to query the actual version of the database server instance: some cloud providers, e.g. Azure, put a gateway
            // in front of the actual instance which reports an "incorrect" version to the PHP client.
            // Ref.: https://docs.microsoft.com/en-us/azure/mariadb/concepts-supported-versions .
            $sql = "SELECT VERSION() version;";
            $result = $this->mysqli->query($sql);
            if ($result) {
                if ($rec = $result->fetch_assoc()) {
                    // The actual server version starts with the following naming scheme: 'X.Y.Z-MariaDB'.
                    $version = $rec['version'];
                }
                $result->close();
                unset($rec);
            }
            // Remove the "replication version hack" prefix, if any: '5.5.5-' (RPL_VERSION_HACK).
            $version = str_replace('5.5.5-', '', $version);
            // Remove the MariaDB suffix after the actual version: '-MariaDB'.
            $pos = stripos($version, "-MariaDB");
            if ($pos !== false) {
                // The suffix is "always" there, hardcoded in 'mysql_version.h':
                // #define MYSQL_SERVER_VERSION		"@VERSION@-MariaDB"
                // unless someone will change the source code.
                $version = substr($version, 0, $pos);
            }
            // Finally, keep just major, minor and patch versions from the reported MariaDB server version.
            $this->serverversion = $version;
        }
        return array('description'=>$this->mysqli->server_info, 'version'=>$version);
    }

    protected function has_breaking_change_quoted_defaults() {
        $version = $this->get_server_info()['version'];
        // Breaking change since 10.2.7: MDEV-13132.
        return version_compare($version, '10.2.7', '>=');
    }

    public function has_breaking_change_sqlmode() {
        $version = $this->get_server_info()['version'];
        // Breaking change since 10.2.4: https://mariadb.com/kb/en/the-mariadb-library/sql-mode/#setting-sql_mode.
        return version_compare($version, '10.2.4', '>=');
    }

    /**
     * It is time to require transactions everywhere.
     *
     * MyISAM is NOT supported!
     *
     * @return bool
     */
    protected function transactions_supported() {
        if ($this->external) {
            return parent::transactions_supported();
        }
        return true;
    }

    /**
     * Does this mariadb instance support fulltext indexes?
     *
     * @return bool
     */
    public function is_fulltext_search_supported() {
        $info = $this->get_server_info();

        if (version_compare($info['version'], '10.0.5', '>=')) {
            return true;
        }
        return false;
    }
}
