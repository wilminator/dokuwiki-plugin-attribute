<?php
/**
 * DokuWiki Plugin attribute (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Mike Wilmes <mwilmes@avc.edu>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

/**
 * Class helper_plugin_attribute
 */
class helper_plugin_attribute extends DokuWiki_Plugin {
    public $success = false;
    protected $storepath = null;
    protected $cache = null;

    public function __construct() {
        $this->loadConfig();
        // Create the path used for attribute data.
        $path = substr($this->conf['store'], 0, 1) == '/' ? $this->conf['store'] : DOKU_INC . $this->conf['store'];
        $this->storepath = ($this->conf['store'] === '' || !io_mkdir_p($path)) ? null : $path;
        // A directory is needed.
        if(is_null($this->storepath)) {
            msg("Attribute: Configuration item 'store' is not set to a writeable directory.", -1);
            return;
        }
        $this->success = true;
        // Create a memory cache for this execution.
        $this->cache = array();
    }

    /**
     * return some info
     */
    public function getInfo() {
        return array(
            'author' => 'Mike Wilmes',
            'email'  => 'mwilmes@avc.edu',
            'date'   => '2015-09-03',
            'name'   => 'Attribute Plugin',
            'desc'   => 'Arbitrary attribute definition and storage for user associated data.',
            'url'    => 'None for now, hoping for http://www.dokuwiki.org/plugin:attribute',
        );
    }

    /**
     * Return info about supported methods in this Helper Plugin
     *
     * @return array of public methods
     */
    public function getMethods() {
        $result   = array();
        $result[] = array(
            'name'       => 'enumerateAttributes',
            'desc'       => "Generates a list of known attributes in the specified namespace for a user.  If user is present, must be an admin, otherwise defaults to currently logged in user.",
            'parameters' => array(
                'namespace' => 'string',
                'user'      => 'string (optional)',
            ),
            'return'     => array('attributes' => 'array'), // returns false on error.
        );
        $result[] = array(
            'name'       => 'enumerateUsers',
            'desc'       => "Generates a list of users that have assigned attributes in the specified namespace.",
            'parameters' => array(
                'namespace' => 'string',
            ),
            'return'     => array('users' => 'array'), // returns false on error.
        );
        $result[] = array(
            'name'       => 'set',
            'desc'       => "Set the value of an attribute in a specified namespace. Returns boolean success (false if something went wrong). If user is present, must be an admin, otherwise defaults to currently logged in user.",
            'parameters' => array(
                'namespace' => 'string',
                'attribute' => 'string',
                'value'     => 'mixed (serializable)',
                'user'      => 'string (optional)',
            ),
            'return'     => array('success' => 'boolean'),
        );
        $result[] = array(
            'name'       => 'exists',
            'desc'       => "Checks if an attribute exists for a user in a given namespace. If user is present, must be an admin, otherwise defaults to currently logged in user.",
            'parameters' => array(
                'namespace' => 'string',
                'attribute' => 'string',
                'user'      => 'string (optional)',
            ),
            'return'     => array('exists' => 'boolean'),
        );
        $result[] = array(
            'name'       => 'del',
            'desc'       => "Deletes attribute data in a specified namespace by its name. If user is present, must be an admin, otherwise defaults to currently logged in user.",
            'parameters' => array(
                'namespace' => 'string',
                'attribute' => 'string',
                'user'      => 'string (optional)',
            ),
            'return'     => array('success' => 'boolean'),
        );
        $result[] = array(
            'name'       => 'get',
            'desc'       => "Retrieves a value for an attribute in a specified namespace. Returns retrieved value or null. \$success out-parameter can be checked to check success (you may have false, null, 0, or '' as stored value). If user is present, must be an admin, otherwise defaults to currently logged in user.",
            'parameters' => array(
                'namespace' => 'string',
                'attribute' => 'string',
                'success'   => 'boolean (out)',
                'user'      => 'string (optional)',
            ),
            'return'     => array('value' => 'mixed'), // returns false on error.
        );
        $result[] = array(
            'name'       => 'purge',
            'desc'       => "Deletes all attribute data for a specified namespace for a user. Only useable by an admin.",
            'parameters' => array(
                'namespace' => 'string',
                'user'      => 'string',
            ),
            'return'     => array('success' => 'boolean'),
        );
        return $result;
    }

    /**
     * Validate that the user may access another user's attribute. If the user
     * is an admin and another user name is supplied, that value is returned.
     * Otherwise the name of the logged in user is supplied. If no user is
     * logged in, null is returned.
     * @param $user
     * @return null|string
     */
    private function validateUser($user) {
        // We need a special circumstance.  If a user is not logged in, but we
        // are performing a login, enable access to the attributes of the user
        // being logged in IF DIRECTLY SPECIFIED.
        global $INFO, $ACT, $USERINFO, $INPUT;
        if($ACT == 'login' && !$USERINFO && $user == $INPUT->str('u')) return $user;
        // This does not meet the special circumstance listed above.
        // Perform rights validation.
        // If no one is logged in, then return null.
        if($_SERVER['REMOTE_USER'] == '') {
            return null;
        }
        // If the user is not an admin, no user is specified, or the
        // named user is not the logged in user, then return the currently
        // logged in user.
        if(!$user || ($user !== $_SERVER['REMOTE_USER'] && !$INFO['isadmin'])) {
            return $_SERVER['REMOTE_USER'];
        }
        // The user is an admin and a name was specified.
        return $user;
    }

    /**
     * Load all attribute data for a user in the specified namespace.
     * This loads all user attribute data from file.  A copy is stored in
     * memory to alleviate repeated file accesses.
     * @param $namespace
     * @param $user
     * @return array|mixed
     */
    private function loadAttributes($namespace, $user) {
        $key      = rawurlencode($namespace) . '.' . rawurlencode($user);
        $filename = $this->storepath . "/" . $key;

        // If the file does not exist, then return an empty attribute array.
        if(!is_file($filename)) {
            return array();
        }

        if(array_key_exists($filename, $this->cache)) {
            return $this->cache[$filename];
        }

        $packet = io_readFile($filename, false);

        // Unserialize returns false on bad data.
        $preserial = @unserialize($packet);
        if($preserial !== false) {
            list($compressed, $serial) = $preserial;
            if($compressed) {
                $serial = gzuncompress($serial);
            }
            $unserial = @unserialize($serial);
            if ($unserial !== false) {
                list($filekey, $data) = $unserial;
                if ($filekey != $key) { $data = array(); }
            }
        }

        // Set a reasonable default if either unserialize failed.
        if ($preserial == false || $unseriala === false) { $data = array(); }

        $this->cache[$filename] = $data;

        return $data;
    }

    /**
     * Saves attributes in $data to a file.  The file is flagged with the
     * namespace and use that the data was saved for. The data and key will
     * normally be compressed, but this can be turned off for debugging.
     * There is an uncompressed flag to denote whether the data was compressed
     * or not, so both compressed and uncompressed data can be loaded
     * regardless of the compression configuration.
     * @param $namespace
     * @param $user
     * @param $data
     * @return bool
     */
    private function saveAttributes($namespace, $user, $data) {
        $key = rawurlencode($namespace) . '.' . rawurlencode($user);
        $filename = $this->storepath . "/" . $key;

        $this->cache[$filename] = $data;

        $serial = serialize(array($key, $data));
        $compressed = $this->conf['no_compress'] === 0;
        if($compressed) {
            $serial = gzcompress($serial);
        }
        $packet = serialize(array($compressed, $serial));

        return io_saveFile($filename, $packet);
    }

    /**
     * Generates a list of users that have assigned attributes in the
     * specified namespace.
     * @param $namespace
     * @return array|bool
     */
    public function enumerateUsers($namespace) {
        if(!$this->success) {
            return false;
        }

        $listing = scandir($this->storepath, SCANDIR_SORT_DESCENDING);

        // Restrict to namespace
        $key = rawurlencode($namespace) . '.';
        $files = array_filter(
            $listing, function ($x) use ($key) {
            return substr($x, 0, strlen($key)) == $key;
            }
        );
        // Get usernames from files
        $users = array_map(
            function ($x) use ($key) {
                return substr($x, strlen($key));
            }, $files
        );

        return $users;
    }

    /**
     * set - Set the value of an attribute in a specified namespace. Returns
     * boolean success (false if something went wrong). If user is present,
     * must be an admin, otherwise defaults to currently logged in user.
     * @param      $namespace
     * @param      $attribute
     * @param      $value
     * @param null $user
     * @return bool
     */
    public function set($namespace, $attribute, $value, $user = null) {
        if(!$this->success) {
            return false;
        }

        $user = $this->validateUser($user);
        if($user === null) {
            return false;
        }
        $lock= $namespace . '.' . $user;
        io_lock($lock);

        $data = $this->loadAttributes($namespace, $user);

        $result = false;
        if($data !== null) {
            // Set the data in the array.
            $data[$attribute] = $value;
            // Store the changed data.
            $result = $this->saveAttributes($namespace, $user, $data);
        }

        io_unlock($lock);

        return $result;
    }

    /**
     * Generates a list of users that have assigned attributes in the
     * specified namespace.
     * @param      $namespace
     * @param null $user
     * @return array|bool
     */
    public function enumerateAttributes($namespace, $user = null) {
        if(!$this->success) {
            return false;
        }

        $user = $this->validateUser($user);
        if($user === null) {
            return false;
        }

        $lock = $namespace . '.' . $user;
        io_lock($lock);

        $data = $this->loadAttributes($namespace, $user);

        io_unlock($lock);

        if($data === null) {
            return false;
        }

        // Return just the keys. The values are cached.
        return array_keys($data);
    }

    /**
     * Checks if an attribute exists for a user in a given namespace. If user
     * is present, must be an admin, otherwise defaults to currently logged in
     * user.
     * @param      $namespace
     * @param      $attribute
     * @param null $user
     * @return bool
     */
    public function exists($namespace, $attribute, $user = null) {
        if(!$this->success) {
            return false;
        }

        $user = $this->validateUser($user);
        if($user === null) {
            return false;
        }

        $lock = $namespace . '.' . $user;
        io_lock($lock);

        $data = $this->loadAttributes($namespace, $user);

        io_unlock($lock);

        if(!is_array($data)) {
            return false;
        }

        return array_key_exists($attribute, $data);
    }

    /**
     * Deletes attribute data in a specified namespace by its name. If user is
     * present, must be an admin, otherwise defaults to currently logged in
     * user.
     * @param      $namespace
     * @param      $attribute
     * @param null $user
     * @return bool
     */
    public function del($namespace, $attribute, $user = null) {
        if(!$this->success) {
            return false;
        }

        $user = $this->validateUser($user);
        if($user === null) {
            return false;
        }

        $lock = $namespace . '.' . $user;
        io_lock($lock);

        $data = $this->loadAttributes($namespace, $user);
        if($data !== null) {
            // Special case- if the attribute already does not exist, then
            // return true. We are at the desired state.
            if(array_key_exists($attribute, $data)) {
                unset($data[$attribute]);
                $result = $this->saveAttributes($namespace, $user, $data);
            } else {
                $result = true;
            }
        } else {
            $result = false;
        }

        io_unlock($lock);

        return $result;
    }

    /**
     * Deletes all attribute data for a specified namespace for a user. Only
     * useable by an admin.
     * @param $namespace
     * @param $user
     * @return bool
     */
    public function purge($namespace, $user) {
        if(!$this->success) {
            return false;
        }

        // Ensure this user is an admin.
        global $INFO;
        if(!$INFO['isadmin']) {
            return false;
        }

        $lock = $namespace . '.' . $user;
        io_lock($lock);

        $key = rawurlencode($namespace) . '.' . rawurlencode($user);
        $filename = $this->storepath . "/" . $key;

        if(file_exists($filename)) {
            $result = unlink($filename);
        } else {
            // If the file does not exist, the desired end state has been
            // reached.
            $result = true;
        }

        io_unlock($lock);

        return $result;
    }

    /**
     * Retrieves a value for an attribute in a specified namespace. Returns
     * retrieved value or null. $success out-parameter can be checked to check
     * success (you may have false, null, 0, or '' as stored value). If user
     * is present, must be an admin, otherwise defaults to currently logged in
     * user.
     * @param            $namespace
     * @param            $attribute
     * @param bool|false $success
     * @param null       $user
     * @return bool
     */
    public function get($namespace, $attribute, &$success = false, $user = null) {
        // Prepare the supplied success flag as false.  It will be changed to
        // true on success.
        $success = false;

        if(!$this->success) {
            return false;
        }

        $user = $this->validateUser($user);
        if($user === null) {
            return false;
        }

        $lock = $namespace . '.' . $user;
        io_lock($lock);

        $data = $this->loadAttributes($namespace, $user);

        io_unlock($lock);

        if($data === null || !array_key_exists($attribute, $data)) {
            return false;
        }

        $success = true;
        return $data[$attribute];
    }
}

// vim:ts=4:sw=4:et:
