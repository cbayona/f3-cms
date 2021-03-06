<?php

namespace FFCMS\Models;

use FFMVC\Helpers;
use FFCMS\{Traits, Mappers, Exceptions, Enums};


/**
 * Users Model Class.
 *
 * @author Vijay Mahrra <vijay@yoyo.org>
 * @copyright (c) Copyright 2016 Vijay Mahrra
 * @license GPLv3 (http://www.gnu.org/licenses/gpl-3.0.html)
 */
class Users extends DB
{
    /**
     * @var \FFCMS\Mappers\Users user mapper
     */
    public $mapper;

    /**
     * @var \FFCMS\Mappers\UsersData  mapper for user's data
     */
    protected $dataMapper;


    /**
     * initialize with array of params, 'db' and 'logger' can be injected
     *
     * @param null|\Log $logger
     * @param null|\DB\SQL $db
     */
    public function __construct(array $params = [], \Log $logger = null, \DB\SQL $db = null)
    {
        parent::__construct($params, $logger, $db);

        $this->dataMapper = new Mappers\UsersData;
    }


    /**
     * Get the associated data mapper
     *
     * @return \FFCMS\Mappers\Users
     */
    public function &getMapper()
    {
        return $this->mapper;
    }


    /**
     * Get the associated data mapper
     *
     * @return \FFCMS\Mappers\UsersData
     */
    public function &getDataMapper()
    {
        return $this->dataMapper;
    }


    /**
     * Get the user mapper by UUID
     *
     * @param string $uuid User UUID
     * @return \FFCMS\Mappers\Users
     */
    public function &getUserByUUID(string $uuid)
    {
        $m = $this->getMapper();
        $m->load(['uuid = ?', $uuid]);
        return $m;
    }


    /**
     * Get the user mapper by email address
     *
     * @param string $email email address
     * @return \FFCMS\Mappers\Users
     */
    public function &getUserByEmail(string $email)
    {
        $m = $this->getMapper();
        $m->load(['email = ?', $email]);
        return $m;
    }


    /**
     * Fetch the users data, optionally only by specified keys
     *
     * @param string $uuid
     * @param array $keys
     * @return array $data
     */
    public function getUserDetails(string $uuid, array $keys = []): array
    {
        $db = \Registry::get('db');

            // initialise return value
        $data = [];
        foreach ($keys as $k) {
            $data[$k] = null;
        }

        if (!empty($keys)) {

            $keys = array_map(function($key) {
                return "'$key'";
            }, $keys);

            $query = sprintf('SELECT * FROM users_data WHERE users_uuid = :uuid AND '.$db->quotekey('key').' IN (%s)',
                join(',', $keys));

        } else {
            $query = sprintf('SELECT * FROM users_data WHERE users_uuid = :uuid');
        }

        if ($rows = $db->exec($query, [':uuid' => $uuid])) {

            foreach ($rows as $r) {
                $data[$r['key']] = Helpers\Str::deserialize($r['value']);
            }

        }
        return $data;
    }


    /**
     * Fetch the users profile, optionally only by specified keys
     *
     * @param string $uuid
     * @param array $keys
     * @return array $data
     */
    public function getProfile(string $uuid, array $keys = []): array
    {
        if (empty($keys)) {
            $keys = Enums\ProfileKeys::values();
        }
        return $this->getUserDetails($uuid, $keys);
    }


    /**
     * Save user's data
     *
     * @param string $uuid
     * @param array $keys
     */
    public function saveData(string $uuid, array $keys = []): array
    {
        if (empty($keys)) {
            return false;
        }
        $db = \Registry::get('db');
        $dataMapper = $this->getDataMapper();
        foreach ($keys as $k => $v) {
            $dataMapper->load(['users_uuid = ? AND ' . $db->quoteKey('key') . ' = ?', $uuid, $k]);
            if (empty($dataMapper->type)) {
                switch ($k) {
                    case 'bio':
                        $dataMapper->type = 'markdown';
                        break;
                    case 'nickname':
                        $dataMapper->type = 'text';
                        break;
                    default:
                        $dataMapper->type = null;
                    break;
                }
            }
            $dataMapper->users_uuid = $uuid;
            $dataMapper->key = $k;
            $dataMapper->value = $v;
            $dataMapper->save();
        }
        return $dataMapper->cast();
    }


    /**
     * Perform a successful post-login action if the user is in the group 'user'
     * and is with the status 'closed', 'suspended', 'cancelled'
     *
     * @param string optional $uuid logout the current mapper user or specified one
     * @return boolean true/false if login permitted
     */
    public function login($uuid = null): bool
    {
        $usersMapper = empty($uuid) ? $this->getMapper() : $this->getUserByUUID($uuid);
        if (null == $usersMapper->uuid) {
            $msg = "User account not found for $uuid";
            throw new Exceptions\Exception($msg);
        }

        // set user scopes
        $scopes = empty($usersMapper->scopes) ? [] : preg_split("/[\s,]+/", $usersMapper->scopes);
        if (!in_array('user', $scopes) || in_array($usersMapper->status, ['closed', 'suspended', 'cancelled'])) {
            $msg = sprintf(_("User %s %s denied login because account group is not in 'user' or account status is in 'closed,suspended,cancelled'."),
                    $usersMapper->firstname, $usersMapper->lastname);
            throw new Exceptions\Exception($msg);
        }

        $usersMapper->login_count++;
        $usersMapper->login_last = Helpers\Time::database();
        $usersMapper->save();

        Audit::instance()->write([
            'users_uuid' => $usersMapper->uuid,
            'actor' => $usersMapper->email,
            'event' => 'User Login',
        ]);

        return true;
    }


    /**
     * Perform a logout action on the given user uuid
     *
     * @param string optional $uuid logout the current mapper user or specified one
     */
    public function logout($uuid = null): bool
    {
        $m = empty($uuid) ? $this->getMapper() : $this->getUserByUUID($uuid);
        if (null !== $m->uuid) {
            Audit::instance()->write([
                'users_uuid' => $m->uuid,
                'event' => 'User Logout',
                'actor' => $m->email,
            ]);
        }
        return true;
    }


    /**
     * Create a template object for a new user
     *
     * @param \FFCMS\Mappers\Users|null $m User Mapper
     * @link http://fatfreeframework.com/sql-mapper
     */
    public function &newUserTemplate($m = null): \FFCMS\Mappers\Users
    {
        if (empty($m)) {
            $this->mapper->reset();
            $m = $this->mapper;
        }

        $m->uuid = $m->setUUID();
        $m->created = Helpers\Time::database();
        $m->login_count = 0;

        if (empty($m->login_last)) {
            $m->login_last = '0000-00-00 00:00:00';
        }

        if (!empty($m->password)) {
            $m->password = Helpers\Str::password($m->password);
        }

        if (empty($m->status)) {
            $m->status = 'registered';
        }

        if (empty($m->scopes)) {
            $m->scopes = 'user';
        }

        return $m;
    }


    /**
     * Register a new user from a newly populated usersMapper object
     *
     * @param Mappers\Users|null $m User Mapper
     * @link http://fatfreeframework.com/sql-mapper
     */
    public function register($m = null)
    {
        if (empty($m)) {
            $m = $this->getMapper();
        }


        // try to save the data
        $m = $this->newUserTemplate($m);
        $result = $m->save();
        if (true !== $result) {
            return $result;
        }

        $audit = Audit::instance();
        $audit->write([
            'users_uuid' => $m->uuid,
            'actor' => $m->email,
            'event' => 'User Registered',
            'new' => $m->cast()
        ]);

        return true;
    }


    /**
     * save (insert/update) a row to the users_data table
     * $data['value'] is automatically encoded or serialized if array/object
     *
     * @param array $data existing data to update
     * @return Users $data newly saved data
     */
    public function saveKey(array $data = [])
    {
        $m = $this->getDataMapper();

        $m->load(['users_uuid = ?', $data['users_uuid']]);
        $oldData = clone $m;

        // set value based on content
        if (empty($data['value']) && !is_numeric($data['value'])) {
                // empty value should be null if not number
            $data['value'] = null;
        } else {

            $v = $data['value'];
            if (is_array($v)) {
                    // serialize to json if array
                $v = json_encode($v, JSON_PRETTY_PRINT);
            } elseif (is_object($v)) {
                    // php serialize if object
                $v = serialize($v);
            }
            $data['value'] = $v;
        }

        if (empty($m->uuid)) {
            $m->uuid = $data['users_uuid'];
        }

        $m->copyfrom($data);
        $m->save();

        $audit = Audit::instance();

        $audit->write([
            'users_uuid' => $m->users_uuid,
            'event' => 'Users Data Updated',
            'old' => $oldData->cast(),
            'new' => $m->cast(),
        ]);

        return $this;
    }

}
